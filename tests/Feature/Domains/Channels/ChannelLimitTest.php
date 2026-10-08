<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use App\Filament\Assistant\Resources\Channels\Pages\CreateChannel;
use App\Filament\Assistant\Resources\Channels\Pages\ListChannels;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The channel limit counts every channel of the tenant, inactive ones included, and is checked in
 * ChannelService::create(); the assistant panel closes its create controls at the limit.
 */
final class ChannelLimitTest extends FeatureTestCase
{
    use SetsRecordLimits;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncChannelWebhookJob::class]);
        $this->seed(TenantAclSeeder::class);
    }

    public function test_service_creates_until_the_limit_then_throws(): void
    {
        $this->limitRecords('channels', 1);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);

            $service->create($assistant, $this->payload());

            try {
                $service->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Channels limit reached: 1 of 1.', $e->getMessage());
            }

            $this->assertSame(1, Channel::query()->count());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);

            $service->create($assistant, $this->payload());
            $service->create($assistant, $this->payload());

            $this->assertSame(2, Channel::query()->count());
        });
    }

    public function test_limit_zero_refuses_the_first_channel(): void
    {
        $this->limitRecords('channels', 0);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $this->expectException(RecordLimitReachedException::class);

            app(ChannelServiceInterface::class)->create($assistant, $this->payload());
        });
    }

    public function test_inactive_channels_count_and_deleting_one_frees_its_place(): void
    {
        $this->limitRecords('channels', 1);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);
            $channel = $service->create($assistant, ['is_active' => false] + $this->payload());

            try {
                $service->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                // an inactive channel still holds its place
            }

            $channel->delete();

            $service->create($assistant, $this->payload());
            $this->assertSame(1, Channel::query()->count());
        });
    }

    public function test_downgrade_keeps_existing_channels_but_forbids_new_ones(): void
    {
        $assistant = Assistant::factory()->create();
        Channel::withoutEvents(fn () => Channel::factory()->count(2)->create(['assistant_id' => $assistant->getKey()]));
        $this->limitRecords('channels', 1);

        $this->inTenant(function () use ($assistant): void {
            try {
                app(ChannelServiceInterface::class)->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertSame(2, Channel::query()->count());
            }
        });
    }

    public function test_count_is_tenant_wide_from_inside_the_assistant_panel(): void
    {
        $first  = Assistant::factory()->create();
        $second = Assistant::factory()->create();
        Channel::withoutEvents(function () use ($first, $second): void {
            Channel::factory()->create(['assistant_id' => $first->getKey()]);
            Channel::factory()->create(['assistant_id' => $second->getKey()]);
        });
        $this->limitRecords('channels', 2);

        $this->actingAsAdmin('assistant');
        // A request to the panel registers its tenancy scope on the model, as in production.
        $this->get(route('filament.assistant.pages.dashboard', ['tenant' => $first]))->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('assistant'));
        Filament::setTenant($first);

        $this->assertSame(1, Channel::query()->count(), 'The panel scopes plain queries to the current assistant.');
        $this->assertSame(2, Channel::countForLimit());
        $this->assertTrue(ChannelResource::isLimitReached());

        $this->expectException(RecordLimitReachedException::class);
        app(ChannelServiceInterface::class)->create($first, $this->payload());
    }

    public function test_create_button_and_page_are_closed_for_admin_at_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        Channel::withoutEvents(fn () => Channel::factory()->create(['assistant_id' => $assistant->getKey()]));
        $this->limitRecords('channels', 1);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        $this->assertFalse(ChannelResource::canCreate());

        Livewire::test(ListChannels::class)
            ->assertActionHidden('create')
            ->assertSee('Limit reached (1 of 1)');

        $this->get(ChannelResource::getUrl('create', tenant: $assistant))->assertForbidden();
    }

    public function test_create_button_is_closed_for_staff_with_permission_at_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        $this->limitRecords('channels', 0);
        $user = $this->actingAsStaff('assistant', Permission::ManageAssistants, Permission::ManageChannels);
        $user->assistants()->attach($assistant);
        Filament::setTenant($assistant);

        $this->assertFalse(ChannelResource::canCreate());
        $this->get(ChannelResource::getUrl('create', tenant: $assistant))->assertForbidden();
    }

    public function test_create_button_is_visible_below_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        Channel::withoutEvents(fn () => Channel::factory()->create(['assistant_id' => $assistant->getKey()]));
        $this->limitRecords('channels', 2);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        $this->assertTrue(ChannelResource::canCreate());

        Livewire::test(ListChannels::class)
            ->assertActionVisible('create')
            ->assertDontSee('Limit reached');
    }

    public function test_race_shows_a_notification_instead_of_an_error(): void
    {
        $assistant = Assistant::factory()->create();
        $this->limitRecords('channels', 1);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $component = Livewire::test(CreateChannel::class)
            ->fillForm(['type' => ChannelTypeEnum::Telegram->value, 'token' => 'raced-token', 'secret_token' => 'raced-secret']);

        // Another request takes the last slot after the page was opened below the limit: the stale
        // page must reach the service and get a notification, not a 403.
        Channel::withoutEvents(fn () => Channel::factory()->create(['assistant_id' => $assistant->getKey()]));

        $component->call('create')->assertNotified(__('staff.channels.limit.reached_title'));

        $this->assertSame(1, Channel::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type'         => ChannelTypeEnum::Telegram->value,
            'token'        => 'token-' . bin2hex(random_bytes(4)),
            'secret_token' => 'secret-' . bin2hex(random_bytes(4)),
            'config'       => [],
            'is_active'    => true,
        ];
    }
}
