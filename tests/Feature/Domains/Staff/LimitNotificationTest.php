<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\AssistantService;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Services\MediaStorageGate;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendLimitNoticeJob;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\LimitNoticeInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitNoticeReason;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;
use Tests\Support\FakeUsageMeter;

/**
 * A tenant's admins hear once about a limit that refuses work: a database notification and an email
 * each, through the real gates and the real job (the test queue is synchronous).
 */
final class LimitNotificationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private FakeTenantLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        $this->limits = new FakeTenantLimits();
        $this->app->instance(TenantLimitsInterface::class, $this->limits);
    }

    public function test_a_refused_record_notifies_every_active_admin_once_by_database_and_mail(): void
    {
        $first  = $this->admin();
        $second = $this->admin();
        $this->admin(['is_active' => false]);
        $this->admin(['status' => UserStatus::Suspended]);
        $this->admin(['is_platform_support' => true]);
        $this->userWithRole(RoleEnum::Analyst);
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertSame(1, $this->notificationsOf($first));
        $this->assertSame(1, $this->notificationsOf($second));
        $this->assertSame(2, DB::table('notifications')->count());

        $data = $this->storedNotification($first);
        $this->assertSame('Limit reached: Assistants', $data['title']);
        $this->assertSame('limit_reached', $data['kind']);
        $this->assertSame('assistants', $data['key']);
        $this->assertSame(1, $data['limit']);
        $this->assertSame(1, $data['used']);
        $this->assertStringContainsString('Used 1 of 1.', $data['body']);
        $this->assertStringContainsString('A new record could not be created.', $data['body']);
        $this->assertStringContainsString('To raise the limit, contact the platform administrator.', $data['body']);
        $this->assertSame('Open the panel', $data['action']['label']);

        $recipients = array_map(static fn ($message): string => $message->getOriginalMessage()->getTo()[0]->getAddress(), $this->sentMail());
        $this->assertEqualsCanonicalizing([$first->email, $second->email], $recipients);
        $this->assertStringContainsString('Limit reached: Assistants', $this->sentMail()[0]->getOriginalMessage()->getSubject());
    }

    public function test_a_second_refusal_in_the_same_episode_sends_nothing_and_queues_nothing(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        Queue::fake();
        $this->assertRefused(fn () => $this->createAssistant());
        $this->assertRefused(fn () => $this->createAssistant());

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->notificationsOf($admin));
        $this->assertCount(1, $this->sentMail());
    }

    public function test_a_changed_limit_starts_a_new_episode(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();
        $this->assertRefused(fn () => $this->createAssistant());

        $this->limits->limits['assistants'] = 2;
        $this->createAssistant();

        $this->assertSame(2, $this->notificationsOf($admin));
    }

    public function test_the_record_that_takes_the_last_place_notifies_after_it_is_saved(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 2;
        Assistant::factory()->create();

        $this->createAssistant();

        $this->assertSame(2, Assistant::query()->count());
        $this->assertSame(1, $this->notificationsOf($admin));
        $data = $this->storedNotification($admin);
        $this->assertStringContainsString('Used 2 of 2.', $data['body']);
        $this->assertStringContainsString('The last available place under this limit has just been taken.', $data['body']);
    }

    public function test_a_record_below_the_last_place_notifies_nobody(): void
    {
        $this->admin();
        $this->limits->limits['assistants'] = 3;
        Assistant::factory()->create();

        $this->createAssistant();

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_the_last_place_is_announced_only_when_the_surrounding_transaction_commits(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 2;
        Assistant::factory()->create();

        DB::transaction(function () use ($admin): void {
            $this->createAssistant();

            $this->assertSame(0, $this->notificationsOf($admin));
        });

        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_a_rolled_back_record_announces_nothing(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 2;
        Assistant::factory()->create();

        try {
            DB::transaction(function (): void {
                $this->createAssistant();

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->notificationsOf($admin));
    }

    public function test_a_per_period_refusal_notifies_once_per_operator_period(): void
    {
        $admin = $this->admin();
        $end   = new DateTimeImmutable('+10 days');
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(500, 500, $end));

        $this->consumeOutbound();
        $this->consumeOutbound();

        $this->assertSame(1, $this->notificationsOf($admin));
        $data = $this->storedNotification($admin);
        $this->assertSame('Limit reached: Outbound messages', $data['title']);
        $this->assertStringContainsString('Used 500 of 500 in the current period.', $data['body']);
        $this->assertStringContainsString('An outgoing message was not sent.', $data['body']);

        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(500, 500, new DateTimeImmutable('+40 days')));
        $this->consumeOutbound();

        $this->assertSame(2, $this->notificationsOf($admin));
    }

    public function test_a_per_period_refusal_without_a_period_uses_the_calendar_month(): void
    {
        $admin = $this->admin();
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(500, 500));

        $this->consumeOutbound();
        $this->consumeOutbound();

        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_a_storage_refusal_notifies_with_sizes(): void
    {
        $admin                                 = $this->admin();
        $this->limits->limits['media_storage'] = 10 * 1024 * 1024;

        try {
            $this->app->make(MediaStorageGate::class)->assertFits(20 * 1024 * 1024, MediaSource::Upload);
            $this->fail('Expected StorageLimitReachedException.');
        } catch (StorageLimitReachedException) {
        }

        $data = $this->storedNotification($admin);
        $this->assertSame('Limit reached: Media storage', $data['title']);
        $this->assertStringContainsString('Used 0 B of 10 MB.', $data['body']);
        $this->assertStringContainsString('A file was not uploaded.', $data['body']);
    }

    public function test_the_text_follows_the_language_of_each_recipient(): void
    {
        $english                            = $this->admin();
        $russian                            = $this->admin(['locale' => 'ru']);
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertSame('Limit reached: Assistants', $this->storedNotification($english)['title']);
        $this->assertSame('Достигнут лимит: Ассистенты', $this->storedNotification($russian)['title']);
        $this->assertStringContainsString('Чтобы поднять лимит, обратитесь к администратору платформы.', $this->storedNotification($russian)['body']);
    }

    public function test_the_operators_wording_replaces_the_who_to_ask_block_and_the_button(): void
    {
        $admin = $this->admin(['locale' => 'ru']);
        $asked = [];
        $this->app->instance(LimitNoticeInterface::class, new class ($asked) implements LimitNoticeInterface {
            /** @param list<string> $asked */
            public function __construct(public array &$asked)
            {
            }

            public function noticeFor(string $tenantId, string $key, string $locale, LimitNoticeReason $reason): LimitNotice
            {
                $this->asked[] = $tenantId . ':' . $key . ':' . $locale . ':' . $reason->value;

                return new LimitNotice('Перейдите на тариф Pro.', 'Тарифы', 'https://shell.example/plans');
            }
        });
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        $data = $this->storedNotification($admin);
        $this->assertStringContainsString('Перейдите на тариф Pro.', $data['body']);
        $this->assertStringNotContainsString('администратору платформы', $data['body']);
        $this->assertSame(['label' => 'Тарифы', 'url' => 'https://shell.example/plans'], $data['action']);
        $this->assertSame([self::TENANT_ID . ':assistants:ru:refused'], $asked);
    }

    public function test_the_operator_is_told_when_a_record_limit_was_only_filled(): void
    {
        $this->admin();
        $reasons = [];
        $this->app->instance(LimitNoticeInterface::class, new class ($reasons) implements LimitNoticeInterface {
            /** @param list<string> $reasons */
            public function __construct(public array &$reasons)
            {
            }

            public function noticeFor(string $tenantId, string $key, string $locale, LimitNoticeReason $reason): ?LimitNotice
            {
                $this->reasons[] = $reason->value;

                return null;
            }
        });
        $this->limits->limits['assistants'] = 2;
        Assistant::factory()->create();

        $this->createAssistant();
        $this->assertRefused(fn () => $this->createAssistant());

        // Same episode: only the first notice asks the operator.
        $this->assertSame(['reached'], $reasons);
    }

    public function test_an_operator_that_throws_is_reported_and_core_writes_its_own_text(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });
        $admin = $this->admin();
        $this->app->instance(LimitNoticeInterface::class, new class () implements LimitNoticeInterface {
            public function noticeFor(string $tenantId, string $key, string $locale, LimitNoticeReason $reason): never
            {
                throw new RuntimeException('operator is down');
            }
        });
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertContains('operator is down', $reported);
        $this->assertStringContainsString('contact the platform administrator', $this->storedNotification($admin)['body']);
    }

    public function test_a_broken_cache_never_changes_the_decision(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('add')->andThrow(new RuntimeException('cache is down'));
        $cache->shouldReceive('forget')->andThrow(new RuntimeException('cache is down'));
        $this->app->instance(Repository::class, $cache);
        $this->admin();
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();

        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertContains('cache is down', $reported);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_a_failing_queue_never_changes_the_decision_and_lets_the_next_refusal_try_again(): void
    {
        $admin                              = $this->admin();
        $this->limits->limits['assistants'] = 1;
        Assistant::factory()->create();
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });
        $real  = $this->app->make(BusDispatcher::class);
        $calls = 0;
        $bus   = Mockery::mock($real)->makePartial();
        $bus->shouldReceive('dispatch')->andReturnUsing(static function (object $job) use (&$calls, $real): mixed {
            if (0 === $calls++) {
                throw new RuntimeException('queue is down');
            }

            return $real->dispatch($job);
        });
        $this->app->instance(BusDispatcher::class, $bus);

        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertContains('queue is down', $reported);
        $this->assertSame(0, $this->notificationsOf($admin));

        // The episode was released, so the next refusal queues the notice again.
        $this->assertRefused(fn () => $this->createAssistant());

        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_a_tenant_without_limits_pays_nothing_and_hears_nothing(): void
    {
        $this->admin();
        Queue::fake();
        Assistant::factory()->count(3)->create();

        $this->createAssistant();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_the_job_is_not_run_twice_for_one_episode(): void
    {
        $admin = $this->admin();
        $job   = new SendLimitNoticeJob(self::TENANT_ID, 'assistants', 'records', 1, 1, 'record_creation', (new DateTimeImmutable())->format(DATE_ATOM), null, 'limit_notice:t:assistants:l1');

        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_the_job_runs_on_the_platform_service_queue(): void
    {
        $job = new SendLimitNoticeJob(self::TENANT_ID, 'assistants', 'records', 1, 1, null, (new DateTimeImmutable())->format(DATE_ATOM), null, 'k');

        $this->assertSame('messaging.system', $job->queue);
    }

    private function consumeOutbound(): void
    {
        $this->app->make(PeriodQuota::class)->consume('outbound_messages', 'unit-' . uniqid(), new DateTimeImmutable(), RefusedWork::OutboundMessage);
    }

    private function createAssistant(): Assistant
    {
        return $this->app->make(AssistantService::class)->create(
            $this->app->make(TenantContextInterface::class)->get(),
            ['name' => 'Bot ' . uniqid()],
        );
    }

    private function assertRefused(callable $creation): void
    {
        try {
            $creation();
            $this->fail('Expected RecordLimitReachedException.');
        } catch (RecordLimitReachedException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        if (isset($attributes['is_platform_support'])) {
            $user->forceFill(['is_platform_support' => true])->save();
        }

        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWithRole(RoleEnum $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function notificationsOf(User $user): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->getKey())->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function storedNotification(User $user): array
    {
        $row = DB::table('notifications')->where('notifiable_id', $user->getKey())->first();

        $this->assertNotNull($row, 'The user has no notification.');

        return json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<\Symfony\Component\Mailer\SentMessage>
     */
    private function sentMail(): array
    {
        return array_values($this->app->make('mailer')->getSymfonyTransport()->messages()->all());
    }
}
