<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Live\StaffNotificationsAnnouncer;
use App\Domains\Staff\Live\StaffNotificationsChanged;
use App\Domains\Staff\Live\StaffNotificationsChannel;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffMessageNotification;
use App\Domains\Staff\Services\StaffNotificationInbox;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Mockery;
use RuntimeException;
use Tests\Feature\Domains\Flow\Live\SigningTestBroadcaster;
use Tests\Feature\FeatureTestCase;

/**
 * The server side of the bell's live updates: a staff user's own private channel, which only that user may join, and
 * the empty announcement sent on it when one of their in-app notifications arrives or is read — only when a
 * broadcaster delivers.
 */
final class StaffNotificationsLiveTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
    }

    public function test_only_the_user_it_names_may_listen_administrators_included(): void
    {
        $channel = $this->app->make(StaffNotificationsChannel::class);
        $user    = User::factory()->create();
        $admin   = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->assertTrue($channel->join($user, self::TENANT_ID, (string) $user->getKey()));
        $this->assertTrue($channel->join($admin, self::TENANT_ID, (string) $admin->getKey()));

        $this->assertFalse($channel->join($admin, self::TENANT_ID, (string) $user->getKey()), 'an administrator on another user');
        $this->assertFalse($channel->join($user, self::TENANT_ID, (string) $admin->getKey()), 'another user');
        $this->assertFalse($channel->join($user, '00000000-0000-0000-0000-0000000000ff', (string) $user->getKey()), 'another tenant');
        $this->assertFalse($channel->join($user, self::TENANT_ID, ''));
    }

    public function test_the_auth_endpoint_signs_only_the_users_own_channel(): void
    {
        $this->useBroadcaster(new SigningTestBroadcaster());

        // Both may open the console, so only the channel itself decides.
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $mine = 'private-' . StaffNotificationsChannel::name(self::TENANT_ID, (string) $user->getKey());

        $this->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])->assertUnauthorized();

        $this->actingAs($user)
            ->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])
            ->assertOk()
            ->assertExactJson(['auth' => 'signed:' . $mine]);

        $this->actingAs($user)
            ->postJson($this->panelUrl('/broadcasting/auth'), [
                'channel_name' => 'private-' . StaffNotificationsChannel::name(self::TENANT_ID, (string) $admin->getKey()),
                'socket_id'    => '1.1',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])
            ->assertForbidden();
    }

    public function test_an_in_app_notification_is_announced_on_the_users_channel_with_no_data(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([StaffNotificationsChanged::class]);
        $user = User::factory()->create();

        $user->notifyNow(new StaffMessageNotification(new StaffMessage('Operator needed')));

        Event::assertDispatchedTimes(StaffNotificationsChanged::class, 1);
        Event::assertDispatched(StaffNotificationsChanged::class, function (StaffNotificationsChanged $event) use ($user): bool {
            $channels = $event->broadcastOn();

            return self::TENANT_ID === $event->tenantId
                && (string) $user->getKey() === $event->userId
                && [] === $event->broadcastWith()
                && 1 === count($channels)
                && 'private-' . StaffNotificationsChannel::name(self::TENANT_ID, (string) $user->getKey()) === $channels[0]->name
                && StaffNotificationsChannel::EVENT === $event->broadcastAs();
        });
    }

    public function test_nothing_is_announced_without_a_broadcaster(): void
    {
        Event::fake([StaffNotificationsChanged::class]);

        User::factory()->create()->notifyNow(new StaffMessageNotification(new StaffMessage('Operator needed')));

        Event::assertNotDispatched(StaffNotificationsChanged::class);
    }

    public function test_reading_is_announced_only_when_it_changed_something(): void
    {
        $user = User::factory()->create();
        $user->notifyNow(new StaffMessageNotification(new StaffMessage('One')));
        $user->notifyNow(new StaffMessageNotification(new StaffMessage('Two')));
        $first = (string) $user->notifications()->firstOrFail()->getKey();

        config(['broadcasting.default' => 'pusher']);
        Event::fake([StaffNotificationsChanged::class]);
        $inbox = $this->app->make(StaffNotificationInbox::class);

        $inbox->markRead($user, $first);
        $inbox->markRead($user, $first);
        $inbox->markAllRead($user);
        $inbox->markAllRead($user);

        Event::assertDispatchedTimes(StaffNotificationsChanged::class, 2);
    }

    public function test_a_failing_announcement_is_reported_and_the_notification_is_kept(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Exceptions::fake();

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->andThrow(new RuntimeException('The websocket server is down'));
        $this->app->instance(StaffNotificationsAnnouncer::class, new StaffNotificationsAnnouncer(
            $this->app->make(BroadcasterStatus::class),
            $this->app->make(TenantContextInterface::class),
            $events,
            $this->app->make(ExceptionHandler::class),
        ));

        $user = User::factory()->create();
        $user->notifyNow(new StaffMessageNotification(new StaffMessage('Operator needed')));

        $this->assertSame(1, $user->unreadNotifications()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_bell_is_given_the_users_channel_only_while_a_broadcaster_delivers(): void
    {
        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);
        $this->actingAs($user);

        $off = $this->get($this->panelUrl('/admin/_probe'))->assertOk()->viewData('page')['props']['notifications'];
        $this->assertNull($off['live']);

        config(['broadcasting.default' => 'pusher']);
        $on = $this->get($this->panelUrl('/admin/_probe'))->assertOk()->viewData('page')['props']['notifications'];
        $this->assertSame([
            'channel' => StaffNotificationsChannel::name(self::TENANT_ID, (string) $user->getKey()),
            'event'   => StaffNotificationsChannel::EVENT,
        ], $on['live']);
    }

    /**
     * Makes `$broadcaster` the default connection, with the application's channels registered on it.
     */
    private function useBroadcaster(Broadcaster $broadcaster): void
    {
        config([
            'broadcasting.default'             => 'testing',
            'broadcasting.connections.testing' => ['driver' => 'testing'],
        ]);

        $manager = $this->app->make(BroadcastManager::class);
        $manager->forgetDrivers();
        $manager->extend('testing', static fn (): Broadcaster => $broadcaster);

        require base_path('routes/channels.php');
    }
}
