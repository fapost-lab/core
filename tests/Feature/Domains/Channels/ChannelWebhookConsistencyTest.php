<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Enums\ChannelWebhookStatus;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Filament\Assistant\Resources\Channels\Pages\CreateChannel;
use App\Filament\Assistant\Resources\Channels\Pages\EditChannel;
use App\Filament\Assistant\Resources\Channels\Pages\ListChannels;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * A provider refusal never fails a channel write: the register outcome is stored on the channel, a deregister
 * refusal is noted for the request, a rotation writes the routing before the provider is called, and the cascades
 * over an assistant's channels run to the end.
 */
final class ChannelWebhookConsistencyTest extends FeatureTestCase
{
    use SetsRecordLimits;

    private const string TOKEN = 'bot-token-SECRET-111';

    /**
     * The order of the routing writes and provider calls.
     *
     * @var list<string>
     */
    private array $events = [];

    private object $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $events         = &$this->events;
        $this->registry = new class ($events) implements ChannelWebhookRegistryInterface {
            /**
             * @param  list<string>  $events
             */
            public function __construct(private array &$events)
            {
            }

            public function set(Channel $channel, TenantInterface $tenant): void
            {
                $this->events[] = 'set:' . $channel->webhook_public_hash;
            }

            public function remove(string $webhookPublicHash): void
            {
                $this->events[] = 'remove:' . $webhookPublicHash;
            }

            public function warmup(TenantInterface $tenant): void
            {
            }
        };

        $this->app->instance(ChannelWebhookRegistryInterface::class, $this->registry);
        // The channel observer is built once per model boot, so the fake has to be there before it is.
        Model::clearBootedModels();
    }

    public function test_a_refused_registration_does_not_fail_the_write_and_is_stored_and_reported(): void
    {
        Exceptions::fake();
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();

        $channel = $this->runIn(fn (): Channel => app(ChannelServiceInterface::class)->create($assistant, $this->payload()));

        $this->assertTrue($channel->exists);
        $this->assertSame(ChannelWebhookStatus::Failed, $channel->webhook_status);
        $this->assertNotNull($channel->webhook_status_at);
        $this->assertTrue($channel->webhookRegistrationFailed());
        Exceptions::assertReported(TelegramApiException::class);
    }

    public function test_an_accepted_registration_is_stored_and_the_status_write_does_not_register_again(): void
    {
        $this->providerAccepts();
        $assistant = Assistant::factory()->create();

        $channel = $this->runIn(fn (): Channel => app(ChannelServiceInterface::class)->create($assistant, $this->payload()));

        $this->assertSame(ChannelWebhookStatus::Registered, $channel->webhook_status);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'setWebhook'))->count());
    }

    public function test_recording_the_outcome_leaves_the_edit_time_of_the_channel_alone(): void
    {
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant, ['updated_at' => now()->subDay()]);
        $before    = $channel->refresh()->updated_at->toIso8601String();
        $recorder  = $this->app->make(ChannelWebhookStatusRecorderInterface::class);

        $recorder->markFailed((string) $channel->getKey());
        $this->assertSame(ChannelWebhookStatus::Failed, $channel->refresh()->webhook_status);

        $recorder->markRegistered((string) $channel->getKey());
        $this->assertSame(ChannelWebhookStatus::Registered, $channel->refresh()->webhook_status);

        $recorder->clear((string) $channel->getKey());
        $this->assertNull($channel->refresh()->webhook_status);
        $this->assertNull($channel->webhook_status_at);
        $this->assertSame($before, $channel->updated_at->toIso8601String());
    }

    public function test_a_refused_deregistration_does_not_fail_the_delete_and_is_noted(): void
    {
        Exceptions::fake();
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);

        $this->runIn(function () use ($channel): void {
            $channel->delete();

            $this->assertTrue(app(ChannelWebhookSyncOutcome::class)->deregisterFailed((string) $channel->getKey()));
            $this->assertFalse(app(ChannelWebhookSyncOutcome::class)->registerFailed((string) $channel->getKey()));
        });

        $this->assertModelMissing($channel);
        Exceptions::assertReported(TelegramApiException::class);
    }

    public function test_a_refused_deactivation_is_noted_and_the_channel_is_off(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant, ['webhook_status' => ChannelWebhookStatus::Failed->value]);

        $this->runIn(function () use ($channel): void {
            app(ChannelServiceInterface::class)->deactivate($channel->fresh());

            $this->assertTrue(app(ChannelWebhookSyncOutcome::class)->deregisterFailed((string) $channel->getKey()));
        });

        $this->assertFalse($channel->refresh()->is_active);
    }

    public function test_the_outcome_does_not_outlive_the_unit_of_work(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);

        $this->runIn(fn () => $channel->delete());

        $this->assertTrue($this->app->make(ChannelWebhookSyncOutcome::class)->hasFailures());

        // What a queue worker does between jobs.
        $this->app->forgetScopedInstances();

        $this->assertFalse($this->app->make(ChannelWebhookSyncOutcome::class)->hasFailures());
    }

    public function test_rotation_writes_the_routing_before_it_calls_the_provider_and_survives_a_refusal(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $old       = $channel->webhook_public_hash;

        $rotated = $this->runIn(function () use ($channel): Channel {
            $this->events = [];

            return app(ChannelServiceInterface::class)->rotateWebhookHash($channel);
        });

        $new = $rotated->webhook_public_hash;

        $this->assertNotSame($old, $new);
        $this->assertSame(['remove:' . $old, 'set:' . $new, 'provider'], $this->events);
        $this->assertSame($new, $channel->refresh()->webhook_public_hash);
        $this->assertSame(ChannelWebhookStatus::Failed, $rotated->webhook_status);
    }

    public function test_registering_again_stores_the_new_outcome_and_skips_an_inactive_channel(): void
    {
        $this->providerAccepts();
        $assistant = Assistant::factory()->create();
        $failed    = $this->channel($assistant, ['webhook_status' => ChannelWebhookStatus::Failed->value]);
        $inactive  = $this->channel($assistant, ['is_active' => false]);

        $this->runIn(function () use ($failed, $inactive): void {
            $service = app(ChannelServiceInterface::class);

            $this->assertSame(ChannelWebhookStatus::Registered, $service->reregisterWebhook($failed)->webhook_status);
            $this->assertNull($service->reregisterWebhook($inactive)->webhook_status);
        });

        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'setWebhook'))->count());
    }

    public function test_registering_again_that_is_refused_reports_and_keeps_the_flag(): void
    {
        Exceptions::fake();
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant, ['webhook_status' => ChannelWebhookStatus::Failed->value]);

        $result = $this->runIn(fn (): Channel => app(ChannelServiceInterface::class)->reregisterWebhook($channel));

        $this->assertTrue($result->webhookRegistrationFailed());
        Exceptions::assertReported(TelegramApiException::class);
    }

    public function test_deactivating_an_assistant_takes_the_webhooks_of_the_other_channels_down_after_a_refusal(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $first     = $this->channel($assistant);
        $second    = $this->channel($assistant);

        $this->runIn(fn () => app(AssistantServiceInterface::class)->deactivate($assistant->fresh()));

        $this->assertFalse($assistant->refresh()->is_active);
        $this->assertFalse($first->refresh()->is_active);
        $this->assertFalse($second->refresh()->is_active);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'deleteWebhook'))->count());
        $this->assertContains('remove:' . $first->webhook_public_hash, $this->events);
        $this->assertContains('remove:' . $second->webhook_public_hash, $this->events);
    }

    public function test_deleting_an_assistant_runs_to_the_end_after_a_refusal(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $first     = $this->channel($assistant);
        $second    = $this->channel($assistant);

        $this->runIn(fn () => $assistant->delete());

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelMissing($assistant);
    }

    public function test_the_assistant_panel_warns_instead_of_failing_when_the_provider_refuses_a_new_channel(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        Livewire::test(CreateChannel::class)
            ->fillForm(['type' => ChannelTypeEnum::Telegram->value, 'token' => self::TOKEN, 'secret_token' => 'secret-1'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('staff.channels.notifications.webhook_failed_title'));

        $this->assertSame(ChannelWebhookStatus::Failed, Channel::query()->sole()->webhook_status);
    }

    public function test_the_assistant_panel_warns_instead_of_failing_when_the_provider_refuses_a_change(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        Livewire::test(EditChannel::class, ['record' => $channel->getKey()])
            ->fillForm(['token' => 'bot-token-NEW-222'])
            ->call('save')
            ->assertNotified(__('staff.channels.notifications.webhook_failed_title'));

        $this->assertSame(ChannelWebhookStatus::Failed, $channel->refresh()->webhook_status);
    }

    public function test_the_assistant_table_flags_a_refused_channel_and_offers_registering_again_only_for_it(): void
    {
        $assistant = Assistant::factory()->create();
        $failed    = $this->channel($assistant, ['webhook_status' => ChannelWebhookStatus::Failed->value]);
        $fine      = $this->channel($assistant, ['webhook_status' => ChannelWebhookStatus::Registered->value]);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        Livewire::test(ListChannels::class)
            ->assertTableActionVisible('reregisterWebhook', $failed)
            ->assertTableActionHidden('reregisterWebhook', $fine);

        $this->providerAccepts();

        Livewire::test(ListChannels::class)
            ->callTableAction('reregisterWebhook', $failed)
            ->assertNotified(__('staff.channels.notifications.webhook_registered_title'));

        $this->assertSame(ChannelWebhookStatus::Registered, $failed->refresh()->webhook_status);
    }

    public function test_a_table_delete_that_the_provider_does_not_confirm_warns(): void
    {
        $this->providerRefuses();
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        Livewire::test(ListChannels::class)
            ->callTableAction('delete', $channel)
            ->assertNotified(__('staff.channels.notifications.webhook_deregister_failed_title'));

        $this->assertModelMissing($channel);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function runIn(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant(), $callback);
    }

    private function providerRefuses(): void
    {
        $events = &$this->events;

        Http::fake(['api.telegram.org/*' => function () use (&$events) {
            $events[] = 'provider';

            return Http::response(['ok' => false, 'description' => 'Unauthorized'], 401);
        }]);
    }

    private function providerAccepts(): void
    {
        $events = &$this->events;

        Http::fake(['api.telegram.org/*' => function () use (&$events) {
            $events[] = 'provider';

            return Http::response(['ok' => true, 'result' => ['username' => 'help_bot']]);
        }]);
    }

    /**
     * A channel stored without the provider being asked.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function channel(Assistant $assistant, array $attributes = []): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'token'        => self::TOKEN,
            ...$attributes,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type'         => ChannelTypeEnum::Telegram->value,
            'token'        => self::TOKEN,
            'secret_token' => 'secret-1',
            'config'       => [],
            'is_active'    => true,
        ];
    }
}
