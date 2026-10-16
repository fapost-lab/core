<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Infrastructure\CorePlatformSettings;
use App\Domains\Tenancy\Models\PlatformSetting;
use App\Domains\Tenancy\Models\PlatformSettingEvent;
use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use Fapost\Foundation\Settings\Contracts\PlatformSettingsInterface;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\DTO\PlatformSettingValue;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Fapost\Foundation\Settings\Exceptions\InvalidPlatformSettingsException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\FeatureTestCase;

/**
 * Core's platform-wide settings: an `.env` default until someone stores a value, read once per
 * request or job, validated and recorded on every write.
 */
final class PlatformSettingsTest extends FeatureTestCase
{
    private const string ITERATIONS = FlowEngineInterface::MAX_ITERATIONS_SETTING;

    private const string REDIRECT = 'tenancy.rename.redirect_days';

    public function test_without_a_stored_value_the_configured_default_applies(): void
    {
        config(['flow.execution.max_iterations' => 250, 'tenancy.rename.redirect_days' => 12]);

        $this->assertSame(250, $this->settings()->integer(self::ITERATIONS));
        $this->assertSame(12, $this->settings()->integer(self::REDIRECT));
    }

    public function test_a_configured_default_outside_the_bounds_is_kept(): void
    {
        config(['flow.execution.max_iterations' => 4]);

        $this->assertSame(4, $this->settings()->integer(self::ITERATIONS), 'An existing install keeps the default it chose.');
    }

    public function test_a_configured_default_of_another_type_falls_back_to_the_code_default(): void
    {
        config(['flow.execution.max_iterations' => 'many']);

        $this->assertSame(100, $this->settings()->integer(self::ITERATIONS));
    }

    public function test_a_stored_value_wins_and_a_reset_brings_the_default_back(): void
    {
        config(['flow.execution.max_iterations' => 100]);

        $this->settings()->update([self::ITERATIONS => 500], 'user:1');
        $this->assertSame(500, $this->settings()->integer(self::ITERATIONS));

        $this->settings()->update([self::ITERATIONS => null], 'user:1');
        $this->assertSame(100, $this->settings()->integer(self::ITERATIONS));
        $this->assertSame(0, PlatformSetting::query()->count());
    }

    public function test_values_say_whether_a_setting_is_stored(): void
    {
        config(['flow.execution.max_iterations' => 100, 'tenancy.rename.redirect_days' => 30]);
        $this->settings()->update([self::REDIRECT => 14], 'user:1');

        $values = collect($this->settings()->values())->keyBy('key');

        $this->assertEquals(new PlatformSettingValue(self::ITERATIONS, 100, 100, false), $values[self::ITERATIONS]);
        $this->assertEquals(new PlatformSettingValue(self::REDIRECT, 14, 30, true), $values[self::REDIRECT]);
    }

    public function test_definitions_carry_the_installation_default_and_are_ordered_by_group(): void
    {
        config(['flow.execution.max_iterations' => 300]);

        $definitions = $this->settings()->definitions();
        $keys        = array_map(static fn (PlatformSettingDefinition $d): string => $d->key, $definitions);

        $this->assertSame([self::ITERATIONS, self::REDIRECT], $keys, 'Flow before Tenants.');
        $this->assertSame(300, $definitions[0]->default);
        $this->assertSame(10, $definitions[0]->min);
        $this->assertSame(10000, $definitions[0]->max);
        $this->assertFalse($definitions[0]->hostModeOnly);
        $this->assertTrue($definitions[1]->hostModeOnly);
    }

    public function test_a_refused_change_writes_nothing(): void
    {
        try {
            $this->settings()->update([self::REDIRECT => 7, self::ITERATIONS => 5, 'flow.unknown' => 1], 'user:1');
            $this->fail('Expected InvalidPlatformSettingsException');
        } catch (InvalidPlatformSettingsException $e) {
            $this->assertSame([
                self::ITERATIONS => 'must be at least 10.',
                'flow.unknown'   => 'is not a platform setting.',
            ], $e->violations);
        }

        $this->assertSame(0, PlatformSetting::query()->count());
        $this->assertSame(0, PlatformSettingEvent::query()->count());
    }

    public function test_a_value_of_the_wrong_type_is_refused(): void
    {
        $this->expectException(InvalidPlatformSettingsException::class);

        $this->settings()->update([self::ITERATIONS => '500'], 'user:1');
    }

    public function test_a_change_needs_an_actor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->update([self::ITERATIONS => 500], ' ');
    }

    public function test_every_change_is_recorded_once_with_its_actor(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');

        $this->settings()->update([self::ITERATIONS => 500, self::REDIRECT => null], 'user:1');
        $this->settings()->update([self::ITERATIONS => 500], 'user:2');
        $this->settings()->update([self::ITERATIONS => null], 'cli:root');

        $events = PlatformSettingEvent::query()->orderBy('id')->get();

        $this->assertCount(2, $events, 'An unchanged value and a reset of nothing record nothing.');
        $this->assertSame([self::ITERATIONS, null, 500, 'user:1'], [$events[0]->key, $events[0]->old_value, $events[0]->new_value, $events[0]->changed_by]);
        $this->assertSame([self::ITERATIONS, 500, null, 'cli:root'], [$events[1]->key, $events[1]->old_value, $events[1]->new_value, $events[1]->changed_by]);
        $this->assertSame('2026-10-16 12:00:00', $events[0]->changed_at->format('Y-m-d H:i:s'));
    }

    public function test_the_table_is_read_once_per_scope_and_again_in_the_next(): void
    {
        $settings = $this->settings();
        $this->assertSame(100, $settings->integer(self::ITERATIONS));

        // Another process stores a value meanwhile.
        PlatformSetting::query()->create(['key' => self::ITERATIONS, 'value' => 700, 'updated_at' => now(), 'updated_by' => 'operator:1']);

        DB::connection('landlord')->enableQueryLog();
        $this->assertSame(100, $settings->integer(self::ITERATIONS), 'This request or job keeps what it read.');
        $this->assertSame(100, $this->settings()->integer(self::ITERATIONS));
        $this->assertSame([], DB::connection('landlord')->getQueryLog());

        $this->app->forgetScopedInstances();

        $this->assertSame(700, $this->settings()->integer(self::ITERATIONS), 'The next request or job reads the store again.');
    }

    public function test_a_stored_value_the_setting_no_longer_accepts_falls_back_to_the_default(): void
    {
        PlatformSetting::query()->create(['key' => self::ITERATIONS, 'value' => 5, 'updated_at' => now(), 'updated_by' => 'operator:1']);

        $this->assertSame(100, $this->settings()->integer(self::ITERATIONS));
    }

    public function test_an_unreadable_store_answers_the_defaults_and_reports_once(): void
    {
        Exceptions::fake();
        Schema::connection('landlord')->drop('platform_settings');
        // A settings service resolved before the fake would keep the real handler and its loaded rows.
        $this->app->forgetScopedInstances();
        config(['tenancy.rename.redirect_days' => 30]);

        $this->assertSame(100, $this->settings()->integer(self::ITERATIONS));
        $this->assertSame(30, $this->settings()->integer(self::REDIRECT));

        Exceptions::assertReportedCount(1);
    }

    public function test_reading_an_unknown_key_or_the_wrong_type_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        $this->settings()->boolean(self::ITERATIONS);
    }

    public function test_the_registry_refuses_a_second_field_for_a_key_and_registrations_after_freeze(): void
    {
        $registry = new PlatformSettingRegistry();
        $field    = new PlatformSettingField('flow.example', PlatformSettingType::Boolean, 'flow.example', false, 'Example', 'An example.', 'Flow');
        $registry->register($field);

        try {
            $registry->register($field);
            $this->fail('Expected LogicException');
        } catch (LogicException) {
        }

        $registry->freeze();

        $this->expectException(LogicException::class);
        $registry->register(new PlatformSettingField('flow.other', PlatformSettingType::Boolean, 'flow.other', false, 'Other', 'Another.', 'Flow'));
    }

    public function test_the_foundation_contract_is_core_and_writes_through_the_same_service(): void
    {
        $contract = $this->app->make(PlatformSettingsInterface::class);
        $this->assertInstanceOf(CorePlatformSettings::class, $contract);

        $contract->update([self::REDIRECT => 3], 'operator:9');

        $this->assertSame(3, $this->settings()->integer(self::REDIRECT));
        $this->assertCount(2, $contract->definitions());
        $this->assertSame('operator:9', PlatformSettingEvent::query()->sole()->changed_by);
    }

    private function settings(): PlatformSettingsReaderInterface&PlatformSettings
    {
        /** @var PlatformSettings */
        return $this->app->make(PlatformSettingsReaderInterface::class);
    }
}
