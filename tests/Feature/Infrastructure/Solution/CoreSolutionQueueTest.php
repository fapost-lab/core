<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Solution;

use Acme\Feedback\RecordFeedbackJob;
use Acme\Feedback\Unsafe\DelayAttributeJob;
use Acme\Feedback\Unsafe\InheritedAttributeJob;
use Acme\Feedback\Unsafe\MiddlewarePropertyJob;
use Acme\Feedback\Unsafe\PrivateHoldingJob;
use Acme\Feedback\Unsafe\QueueMethodJob;
use Acme\Feedback\Unsafe\TimeoutAttributeJob;
use Acme\Feedback\Unsafe\UniqueJob;
use Acme\Feedback\Unsafe\UniqueUntilProcessingJob;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Foundation\Solution\Queue\SolutionJobRejectedException;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Feature\FeatureTestCase;
use Tests\Support\ForeignSolutionJob;
use Tests\Support\InstallsFeedbackSolution;

/**
 * The one door a Solution queues through: what it stamps on the job, and what it refuses before anything is queued.
 */
final class CoreSolutionQueueTest extends FeatureTestCase
{
    use InstallsFeedbackSolution;

    private TenantInterface $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);
        $this->tenant = $tenant;

        $this->installFeedback();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->removeFeedback();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string<SolutionJob>, 1: string}>
     */
    public static function unsafeShapes(): array
    {
        return [
            'timeout attribute'             => [TimeoutAttributeJob::class, 'Illuminate\\Queue\\Attributes\\Timeout'],
            'delay attribute'               => [DelayAttributeJob::class, 'Illuminate\\Queue\\Attributes\\Delay'],
            'attribute of a parent class'   => [InheritedAttributeJob::class, 'Illuminate\\Queue\\Attributes\\Queue'],
            'a queue() method'              => [QueueMethodJob::class, 'queue() method'],
            'a middleware property'         => [MiddlewarePropertyJob::class, 'middleware property'],
            'ShouldBeUnique'                => [UniqueJob::class, 'ShouldBeUnique'],
            'ShouldBeUniqueUntilProcessing' => [UniqueUntilProcessingJob::class, 'ShouldBeUnique'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function plainPayloads(): array
    {
        return [
            'null'          => [null],
            'bool'          => [true],
            'int'           => [7],
            'float'         => [1.5],
            'string'        => ['text'],
            'backed enum'   => [RoleEnum::Admin],
            'empty array'   => [[]],
            'nested arrays' => [['a' => [1, 'two', null, 3.0, RoleEnum::Admin], 'b' => [[true]]]],
        ];
    }

    /**
     * @return array<string, array{0: callable(): mixed, 1: string}>
     */
    public static function unsupportedPayloads(): array
    {
        return [
            'object'              => [static fn (): mixed => new stdClass(), 'stdClass'],
            'closure'             => [static fn (): mixed => static fn (): int => 1, 'Closure'],
            'object in an array'  => [static fn (): mixed => ['ok', ['deep' => new stdClass()]], 'array'],
            'closure in an array' => [static fn (): mixed => [static fn (): int => 1], 'array'],
            'resource'            => [static fn (): mixed => fopen('php://memory', 'r'), 'resource'],
        ];
    }

    public function test_a_timeout_below_one_second_is_refused(): void
    {
        foreach ([0, -1] as $timeout) {
            $job          = new RecordFeedbackJob('quick');
            $job->timeout = $timeout;

            $this->assertRefused(fn () => $this->dispatch($job), 'timeout of ' . $timeout . ' seconds');
        }

        $one          = new RecordFeedbackJob('one');
        $one->timeout = 1;
        $this->dispatch($one);

        Bus::assertDispatchedTimes(RecordFeedbackJob::class, 1);
    }

    /**
     * @param  class-string<SolutionJob>  $class
     */
    #[DataProvider('unsafeShapes')]
    public function test_a_job_that_chooses_its_own_queue_timing_or_locking_is_refused(string $class, string $messagePart): void
    {
        $this->assertRefused(fn () => $this->dispatch(new $class()), $messagePart);
    }

    public function test_an_empty_middleware_property_is_not_refused(): void
    {
        $job             = new MiddlewarePropertyJob();
        $job->middleware = [];

        $this->dispatch($job);

        Bus::assertDispatchedTimes(MiddlewarePropertyJob::class, 1);
    }

    public function test_a_private_property_of_a_parent_class_is_scanned_too(): void
    {
        $this->assertRefused(fn () => $this->dispatch(new PrivateHoldingJob(new stdClass())), 'property $held of type stdClass');
        $this->assertRefused(fn () => $this->dispatch(new PrivateHoldingJob(static fn (): int => 1)), 'property $held of type Closure');

        $this->dispatch(new PrivateHoldingJob('plain'));

        Bus::assertDispatchedTimes(PrivateHoldingJob::class, 1);
    }

    public function test_the_job_is_stamped_with_the_tenant_and_routed_to_the_solutions_own_queue(): void
    {
        $this->dispatch(new RecordFeedbackJob('hi'));

        Bus::assertDispatchedTimes(RecordFeedbackJob::class, 1);
        Bus::assertDispatched(
            RecordFeedbackJob::class,
            fn (RecordFeedbackJob $job): bool => $job->tenantId === $this->tenant->getId()
                && 'redis-long' === $job->connection
                && 'solution-jobs.feedback' === $job->queue
                && null === $job->delay
                && 0 === $job->postponements
                && 'hi' === $job->title,
        );
    }

    public function test_a_delay_is_passed_on_and_a_non_positive_one_means_no_delay(): void
    {
        $this->dispatch(new RecordFeedbackJob('later'), 120);
        $this->dispatch(new RecordFeedbackJob('now'), 0);
        $this->dispatch(new RecordFeedbackJob('negative'), -5);

        Bus::assertDispatched(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'later' === $job->title && 120 === $job->delay);
        Bus::assertDispatched(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'now' === $job->title && null === $job->delay);
        Bus::assertDispatched(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'negative' === $job->title && null === $job->delay);
    }

    public function test_a_job_dispatched_outside_a_tenant_is_refused(): void
    {
        $context = Mockery::mock(TenantContextInterface::class);
        $context->shouldReceive('isResolved')->andReturn(false);
        $this->app->instance(TenantContextInterface::class, $context);

        $this->assertRefused(fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob()), 'outside a tenant');
    }

    public function test_a_job_of_a_solution_that_is_not_installed_is_refused(): void
    {
        $this->assertRefused(fn () => $this->dispatch(new ForeignSolutionJob('ghost')), 'Solution "ghost", which is not installed');
    }

    public function test_a_job_class_outside_the_package_of_the_solution_it_names_is_refused(): void
    {
        $this->assertRefused(fn () => $this->dispatch(new ForeignSolutionJob('feedback')), 'is not a class of the package of Solution "feedback"');
    }

    public function test_a_timeout_above_the_limit_is_refused_and_the_limit_itself_is_allowed(): void
    {
        $over          = new RecordFeedbackJob('over');
        $over->timeout = SolutionQueueInterface::MAX_TIMEOUT_SECONDS + 1;

        $this->assertRefused(fn () => $this->dispatch($over), 'timeout of 1501 seconds');

        $atLimit          = new RecordFeedbackJob('limit');
        $atLimit->timeout = SolutionQueueInterface::MAX_TIMEOUT_SECONDS;
        $this->dispatch($atLimit);

        Bus::assertDispatchedTimes(RecordFeedbackJob::class, 1);
    }

    #[DataProvider('plainPayloads')]
    public function test_null_scalars_backed_enums_and_arrays_of_them_are_accepted(mixed $payload): void
    {
        $this->dispatch(new RecordFeedbackJob('plain', $payload));

        Bus::assertDispatchedTimes(RecordFeedbackJob::class, 1);
    }

    #[DataProvider('unsupportedPayloads')]
    public function test_objects_closures_and_arrays_holding_them_are_refused_with_the_property_name(callable $make, string $type): void
    {
        $job = new RecordFeedbackJob('bad', $make());

        $this->assertRefused(fn () => $this->dispatch($job), 'property $extra of type ' . $type);
        Bus::assertNothingDispatched();
    }

    public function test_a_model_valued_property_is_refused(): void
    {
        $job = new RecordFeedbackJob('model', $this->tenant);

        $this->assertRefused(fn () => $this->dispatch($job), 'property $extra');
        Bus::assertNothingDispatched();
    }

    public function test_a_refused_job_is_not_stamped(): void
    {
        $job = new ForeignSolutionJob('ghost');

        try {
            $this->dispatch($job);
        } catch (SolutionJobRejectedException) {
        }

        $this->assertNull($job->tenantId);
        $this->assertNull($job->queue);
        $this->assertNull($job->connection);
    }

    /**
     * @param  callable(): mixed  $dispatch
     */
    private function assertRefused(callable $dispatch, string $messagePart): void
    {
        try {
            $dispatch();
        } catch (SolutionJobRejectedException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());
            Bus::assertNothingDispatched();

            return;
        }

        $this->fail('The dispatcher should have refused the job.');
    }

    private function dispatch(SolutionJob $job, int $delay = 0): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant(
            $this->tenant,
            fn () => $this->app->make(SolutionQueueInterface::class)->dispatch($job, $delay),
        );
    }
}
