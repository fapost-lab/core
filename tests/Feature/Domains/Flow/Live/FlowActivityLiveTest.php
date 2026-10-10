<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Live;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Live\FlowActivityChanged;
use App\Domains\Flow\Live\FlowActivityChannel;
use App\Domains\Flow\Live\FlowActivityNotifier;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Feature\FeatureTestCase;

/**
 * The server side of live updates: a session write announces itself on its assistant's private channel only when a
 * broadcaster delivers and someone watches, after the write commits, at most once per throttle window, never fatally;
 * and only the right people may listen.
 */
final class FlowActivityLiveTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        $this->assistant = Assistant::factory()->create();
    }

    public function test_nothing_is_announced_without_a_delivering_broadcaster(): void
    {
        foreach (['null', 'log'] as $broadcaster) {
            config(['broadcasting.default' => $broadcaster]);
            $this->watch();

            $session = $this->newSession();
            Event::fake([FlowActivityChanged::class]);

            $this->assertFalse($this->app->make(BroadcasterStatus::class)->enabled());
            $session->saveWithOptimisticLock(['status' => FlowSessionStatus::Completed]);

            Event::assertNotDispatched(FlowActivityChanged::class);
            $this->assertFalse($this->watchers()->isWatched(self::TENANT_ID, (string) $this->assistant->getKey()), 'nothing is marked either');
        }
    }

    public function test_nothing_is_announced_while_nobody_watches(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([FlowActivityChanged::class]);

        $this->newSession()->saveWithOptimisticLock(['current_node_id' => 'next']);

        Event::assertNotDispatched(FlowActivityChanged::class);
    }

    public function test_a_new_session_is_announced_to_a_watcher(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->watch();
        Event::fake([FlowActivityChanged::class]);

        $this->newSession();

        Event::assertDispatchedTimes(FlowActivityChanged::class, 1);
    }

    public function test_session_writes_are_announced_at_most_once_per_window(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->watch();

        $session = $this->newSession();
        $this->openWindow();
        Event::fake([FlowActivityChanged::class]);

        $session->saveWithOptimisticLock(['current_node_id' => 'next']);
        $session->saveWithOptimisticLock(['current_node_id' => 'after']);

        Event::assertDispatchedTimes(FlowActivityChanged::class, 1);
        Event::assertDispatched(
            FlowActivityChanged::class,
            fn (FlowActivityChanged $event): bool => self::TENANT_ID === $event->tenantId && (string) $this->assistant->getKey() === $event->assistantId,
        );

        $this->openWindow();
        $session->saveWithOptimisticLock(['status' => FlowSessionStatus::Completed]);

        Event::assertDispatchedTimes(FlowActivityChanged::class, 2);
    }

    public function test_the_announcement_waits_for_the_commit(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->watch();
        $session = $this->newSession();
        $this->openWindow();
        Event::fake([FlowActivityChanged::class]);

        DB::transaction(function () use ($session): void {
            $session->saveWithOptimisticLock(['current_node_id' => 'next']);

            Event::assertNotDispatched(FlowActivityChanged::class);
        });

        Event::assertDispatchedTimes(FlowActivityChanged::class, 1);
    }

    public function test_a_failing_broadcaster_neither_fails_the_committed_step_nor_skips_other_after_commit_work(): void
    {
        $this->useBroadcaster(new class () extends Broadcaster {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('The websocket server is down');
            }
        });
        Exceptions::fake();
        $this->watch();

        $session = $this->newSession();
        $this->openWindow();
        $siblingRan = false;

        DB::transaction(function () use ($session, &$siblingRan): void {
            $session->saveWithOptimisticLock(['status' => FlowSessionStatus::Completed]);
            DB::afterCommit(static function () use (&$siblingRan): void {
                $siblingRan = true;
            });
        });

        $this->assertSame(FlowSessionStatus::Completed, $session->fresh()?->status);
        $this->assertTrue($siblingRan, 'the after-commit work queued after the announcement still ran');
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_failing_cache_does_not_fail_the_write(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Exceptions::fake();

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('has')->andThrow(new RuntimeException('Redis is gone'));

        $notifier = new FlowActivityNotifier(
            $this->app->make(BroadcasterStatus::class),
            new FlowActivityWatchers($this->app->make(BroadcasterStatus::class), $cache),
            $cache,
            $this->app->make(Dispatcher::class),
            $this->app->make(ExceptionHandler::class),
        );

        $notifier->touched($this->newSession());

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_announcement_names_the_private_channel_and_carries_nothing(): void
    {
        $event = new FlowActivityChanged(self::TENANT_ID, 'assistant-1');

        $this->assertEquals([new PrivateChannel('tenant.' . self::TENANT_ID . '.assistant.assistant-1.flow')], $event->broadcastOn());
        $this->assertSame('flow.activity', $event->broadcastAs());
        $this->assertSame([], $event->broadcastWith());
        $this->assertSame('messaging.system', $event->broadcastQueue());
    }

    public function test_only_a_user_who_may_see_the_assistants_sessions_may_listen(): void
    {
        $this->seed(TenantAclSeeder::class);
        config(['broadcasting.default' => 'pusher']);

        $channel   = $this->app->make(FlowActivityChannel::class);
        $assistant = (string) $this->assistant->getKey();
        $admin     = $this->admin();

        $this->assertTrue($channel->join($admin, self::TENANT_ID, $assistant));
        $this->assertTrue($this->watchers()->isWatched(self::TENANT_ID, $assistant), 'a listener counts as a watcher');

        $this->assertFalse($channel->join($admin, self::OTHER_TENANT_ID, $assistant), 'another tenant than the host');
        $this->assertFalse($channel->join($admin, self::TENANT_ID, 'not-a-uuid'));
        $this->assertFalse($channel->join($admin, self::TENANT_ID, Str::ulid()->toRfc4122()), 'no such assistant');

        $foreign = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->assertFalse($channel->join($admin, self::TENANT_ID, (string) $foreign->getKey()), 'an assistant of another tenant');

        $operator = $this->operator();
        $this->assertFalse($channel->join($operator, self::TENANT_ID, $assistant), 'without the sessions permission');

        $operator->givePermissionTo(Permission::ViewFlowSessions->value);
        $this->assertTrue($channel->join($operator->fresh(), self::TENANT_ID, $assistant));
    }

    public function test_channel_authorization_runs_behind_the_tenant_and_the_session(): void
    {
        $route = Route::getRoutes()->match(Request::create($this->panelUrl('/broadcasting/auth'), 'POST'));

        $this->assertContains('broadcasting', $route->gatherMiddleware());
    }

    public function test_the_auth_endpoint_signs_only_for_who_may_listen(): void
    {
        $this->seed(TenantAclSeeder::class);
        $this->useBroadcaster(new SigningTestBroadcaster());

        $assistant = (string) $this->assistant->getKey();
        $mine      = 'private-' . FlowActivityChannel::name(self::TENANT_ID, $assistant);

        $this->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])->assertUnauthorized();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])
            ->assertOk()
            ->assertExactJson(['auth' => 'signed:' . $mine]);

        $this->actingAs($admin)
            ->postJson($this->panelUrl('/broadcasting/auth'), [
                'channel_name' => 'private-' . FlowActivityChannel::name(self::OTHER_TENANT_ID, $assistant),
                'socket_id'    => '1.1',
            ])
            ->assertForbidden();

        // May see flow sessions, but this assistant is not theirs.
        $stranger = $this->operator(attach: false);
        $stranger->givePermissionTo(Permission::ViewFlowSessions->value);
        $this->actingAs($stranger)
            ->postJson($this->panelUrl('/broadcasting/auth'), ['channel_name' => $mine, 'socket_id' => '1.1'])
            ->assertForbidden();
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

    private function watch(): void
    {
        $this->watchers()->watch(self::TENANT_ID, (string) $this->assistant->getKey());
    }

    private function watchers(): FlowActivityWatchers
    {
        return $this->app->make(FlowActivityWatchers::class);
    }

    /**
     * Ends the throttle window, as if two seconds had passed.
     */
    private function openWindow(): void
    {
        CacheFacade::forget('flow-activity:' . self::TENANT_ID . ':' . $this->assistant->getKey());
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function operator(bool $attach = true): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        if ($attach) {
            $user->assistants()->attach($this->assistant);
        }

        return $user;
    }

    private function newSession(): FlowSession
    {
        $flow       = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey()]);
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);
    }
}

/**
 * A Pusher-style broadcaster without Pusher: it authorizes through the application's channels and "signs" with the
 * channel name, so a test can tell an allowed subscription from a refused one.
 */
final class SigningTestBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request): mixed
    {
        $channelName = $this->normalizeChannelName((string) $request->channel_name);

        if ('' === $channelName || ($this->isGuardedChannel((string) $request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException();
        }

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return ['auth' => 'signed:' . $request->channel_name];
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
    }
}
