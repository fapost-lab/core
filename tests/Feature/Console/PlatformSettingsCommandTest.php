<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Tenancy\Models\PlatformSetting;
use App\Domains\Tenancy\Models\PlatformSettingEvent;
use App\Domains\Tenancy\Services\PlatformSettings;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\FeatureTestCase;

final class PlatformSettingsCommandTest extends FeatureTestCase
{
    private const KEY = 'flow.execution.max_iterations';

    public function test_the_list_shows_the_default_and_then_the_stored_value(): void
    {
        $this->assertSame(0, Artisan::call('platform:settings'));
        $this->assertMatchesRegularExpression('/' . preg_quote(self::KEY, '/') . '\s*\|[^|]*\|\s*default\s*\|/', Artisan::output());

        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '250'])->assertSuccessful();
        $this->app->forgetScopedInstances();

        $this->assertSame(0, Artisan::call('platform:settings'));
        $this->assertMatchesRegularExpression('/' . preg_quote(self::KEY, '/') . '\s*\|\s*250\s*\|\s*stored\s*\|/', Artisan::output());
    }

    public function test_the_list_marks_host_mode_only_settings(): void
    {
        $this->assertSame(0, Artisan::call('platform:settings'));
        $this->assertStringContainsString('host mode only', Artisan::output());
    }

    public function test_set_stores_the_value_and_records_the_change(): void
    {
        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '250'])->assertSuccessful();

        $this->app->forgetScopedInstances();

        $this->assertSame(250, $this->app->make(PlatformSettings::class)->integer(self::KEY));

        $event = PlatformSettingEvent::query()->where('key', self::KEY)->sole();
        $this->assertSame(250, $event->new_value);
        $this->assertStringStartsWith('cli:', $event->changed_by);
    }

    public function test_set_refuses_a_value_out_of_range_and_stores_nothing(): void
    {
        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '5'])->assertFailed();

        $this->assertNothingStored();
    }

    public function test_set_refuses_a_value_that_is_not_an_integer(): void
    {
        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '12x'])->assertFailed();
        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '1.5'])->assertFailed();

        $this->assertNothingStored();
    }

    public function test_an_unknown_key_fails(): void
    {
        $this->artisan('platform:settings:set', ['key' => 'no.such_setting', 'value' => '1'])->assertFailed();
        $this->artisan('platform:settings:reset', ['key' => 'no.such_setting'])->assertFailed();

        $this->assertNothingStored();
    }

    public function test_reset_brings_the_default_back(): void
    {
        $default = $this->app->make(PlatformSettings::class)->integer(self::KEY);

        $this->artisan('platform:settings:set', ['key' => self::KEY, 'value' => '250'])->assertSuccessful();
        $this->artisan('platform:settings:reset', ['key' => self::KEY])->assertSuccessful();

        $this->app->forgetScopedInstances();

        $this->assertSame($default, $this->app->make(PlatformSettings::class)->integer(self::KEY));
        $this->assertSame(0, PlatformSetting::query()->count());
        $this->assertSame(2, PlatformSettingEvent::query()->where('key', self::KEY)->count());
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, PlatformSetting::query()->count());
        $this->assertSame(0, PlatformSettingEvent::query()->count());
    }
}
