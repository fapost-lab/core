<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Contact\Jobs\SendContactNotificationJob;
use App\Domains\Conversation\Jobs\FetchConversationMediaJob;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Conversation\Jobs\UpdateConversationDeliveryStatusJob;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use App\Jobs\Messaging\BroadcastSendJob;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A stopped tenant's runtime stands still, so every queued job in `app/` must have been decided:
 * it does runtime work and carries {@see RespectsTenantAccessMode}, or it keeps running when the
 * tenant is stopped. A new job fails this test until it is added to one of the two lists below,
 * which is the moment to ask which it is.
 *
 * Runtime work is anything the tenant's customers or schedules cause: inbound messages, wake-ups
 * and timeouts, event triggers, broadcasts, notifications sent from a flow. Housekeeping is what
 * the platform does for the tenant regardless (the conversation record, delivery statuses,
 * webhook sync, activation mail, media cleanup).
 */
final class RuntimeJobAccessModeTest extends TestCase
{
    /**
     * Jobs that do not run, or wait, while the tenant is stopped.
     *
     * @var list<class-string>
     */
    private const array RUNTIME_JOBS = [
        IncomingMessageJob::class,
        DispatchFlowTriggerEventJob::class,
        StartFlowFromEventJob::class,
        SendContactNotificationJob::class,
        SendStaffNotificationJob::class,
        ResumeDelayedFlowSessionJob::class,
        ResumeTimedOutSendMessageNodeJob::class,
        RunBroadcastJob::class,
        SendBroadcastRecipientJob::class,
        BroadcastSendJob::class,
    ];

    /**
     * Jobs that keep running in a stopped tenant.
     *
     * @var list<class-string>
     */
    private const array KEEPS_RUNNING_JOBS = [
        PersistConversationMessageJob::class,
        UpdateConversationDeliveryStatusJob::class,
        FetchConversationMediaJob::class,
        SyncChannelWebhookJob::class,
        SendActivationEmailJob::class,
        CleanupSoftDeletedMediaJob::class,
    ];

    public function test_every_queued_job_is_decided(): void
    {
        $this->assertSame(
            [],
            $this->undecided($this->queuedJobsInApp()),
            'These queued jobs are in neither RUNTIME_JOBS nor KEEPS_RUNNING_JOBS of ' . self::class . '. '
            . 'Decide whether the job stops while a tenant is stopped: if so, make it implement ' . TenantAccessGatedJob::class
            . ' and return ' . RespectsTenantAccessMode::class . ' from middleware(); if not, list it as keeping running.',
        );
    }

    public function test_every_runtime_job_is_gated_by_the_access_mode(): void
    {
        $this->assertSame([], $this->ungated(self::RUNTIME_JOBS));
    }

    public function test_a_job_that_keeps_running_is_not_gated(): void
    {
        foreach (self::KEEPS_RUNNING_JOBS as $class) {
            $this->assertNotContains(
                TenantAccessGatedJob::class,
                class_implements($class),
                $class . ' is listed as keeping running but is gated: move it to RUNTIME_JOBS or drop the gate.',
            );
        }
    }

    public function test_the_lists_hold_only_queued_jobs_and_do_not_overlap(): void
    {
        $queued = $this->queuedJobsInApp();

        foreach ([...self::RUNTIME_JOBS, ...self::KEEPS_RUNNING_JOBS] as $class) {
            $this->assertContains($class, $queued, $class . ' is listed but is not a queued job in app/.');
        }

        $this->assertSame([], array_values(array_intersect(self::RUNTIME_JOBS, self::KEEPS_RUNNING_JOBS)));
    }

    /**
     * A "Class@method" queue payload (the Go gateway writes them) is run by `Job::fire()` without
     * the queue's middleware pipeline, so the class it names has to run the job's middleware itself.
     */
    public function test_a_class_at_method_queue_handler_cannot_bypass_the_gate(): void
    {
        $handlers = [];

        foreach (Finder::create()->files()->name('*.go')->notName('*_test.go')->in(base_path('gateway')) as $file) {
            preg_match_all('/`([A-Za-z0-9_\\\\]+)@(\w+)`/', $file->getContents(), $matches);

            foreach ($matches[1] as $class) {
                $handlers[$class] = true;
            }
        }

        $this->assertNotEmpty($handlers, 'Expected the gateway to name a Class@method queue handler.');

        foreach (array_keys($handlers) as $class) {
            $this->assertTrue(class_exists($class), $class . ' is named by the gateway but does not exist.');
            $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                '->middleware()',
                $source,
                $class . ' is a Class@method queue handler and must run the job middleware, or the tenant access gate is bypassed.',
            );
            $this->assertStringContainsString('Pipeline', $source);
        }
    }

    public function test_the_scan_catches_a_new_job_and_a_runtime_job_without_the_gate(): void
    {
        $newJob = new class implements ShouldQueue {};

        $this->assertSame([$newJob::class], $this->undecided([...$this->queuedJobsInApp(), $newJob::class]));
        $this->assertSame([$newJob::class], $this->ungated([...self::RUNTIME_JOBS, $newJob::class]));
    }

    /**
     * @param  list<class-string>  $jobs
     *
     * @return list<class-string>
     */
    private function undecided(array $jobs): array
    {
        return array_values(array_diff($jobs, self::RUNTIME_JOBS, self::KEEPS_RUNNING_JOBS));
    }

    /**
     * @param  list<class-string>  $jobs
     *
     * @return list<class-string>
     */
    private function ungated(array $jobs): array
    {
        $ungated = [];

        foreach ($jobs as $class) {
            $reflection = new ReflectionClass($class);
            $job        = $reflection->newInstanceWithoutConstructor();

            $gated = $job instanceof TenantAccessGatedJob
                && method_exists($job, 'middleware')
                && [] !== array_filter(
                    $job->middleware(),
                    static fn (mixed $middleware): bool => $middleware instanceof RespectsTenantAccessMode,
                );

            if (! $gated) {
                $ungated[] = $class;
            }
        }

        return $ungated;
    }

    /**
     * Every concrete queued job under `app/`, mail excepted (a queued mailable belongs to no tenant's runtime).
     *
     * @return list<class-string>
     */
    private function queuedJobsInApp(): array
    {
        $jobs = [];

        foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isInstantiable()
                && $reflection->implementsInterface(ShouldQueue::class)
                && ! $reflection->isSubclassOf(Mailable::class)) {
                $jobs[] = $class;
            }
        }

        sort($jobs);

        return $jobs;
    }
}
