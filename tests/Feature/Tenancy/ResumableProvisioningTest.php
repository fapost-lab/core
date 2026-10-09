<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Exceptions\FirstAdminConflictException;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\ValueObjects\ProvisioningProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Tests\Feature\Concerns\BuildsProvisioningService;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FailingTenantRepository;

/**
 * Provisioning by id survives a failure at any step and a killed run (AC-05 to AC-07). The landlord
 * row is real; schema creation and the tenant switch are fakes (see {@see BuildsProvisioningService}).
 */
final class ResumableProvisioningTest extends FeatureTestCase
{
    use BuildsProvisioningService;

    private const string SECRET = 'S3cret-p@ss-value';

    private bool $failing = false;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failingSteps(): array
    {
        return [
            'create_schema'    => ['create_schema'],
            'migrate_settings' => ['migrate_settings'],
            'migrate_tenant'   => ['migrate_tenant'],
            'acl'              => ['acl'],
            'first_admin'      => ['first_admin'],
            'webhooks'         => ['webhooks'],
            'activate'         => ['activate'],
        ];
    }

    #[DataProvider('failingSteps')]
    public function test_a_failure_at_a_step_leaves_pending_with_the_step_and_a_repeat_completes(string $step): void
    {
        $repository = new FailingTenantRepository();
        $webhooks   = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup')->andReturnUsing(function () use ($step): void {
            if ('webhooks' === $step && $this->failing) {
                throw new RuntimeException('webhook store down ' . self::SECRET);
            }
        });
        $service = $this->provisioningService($repository, $webhooks);
        $id      = $this->reserve($service, 'acme');

        $errorClass    = 'first_admin' === $step ? FirstAdminConflictException::class : RuntimeException::class;
        $this->failing = true;
        $this->breakStep($step, $repository);

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET, 'Acme Admin');
            $this->fail('Expected the step to fail.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame('first_admin' === $step ? ProvisioningProblem::Conflict : ProvisioningProblem::StepFailed, $e->problem);
            $this->assertSame($id, $e->tenantId);
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertInstanceOf($errorClass, $e->getPrevious());
        }

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame($step . ': ' . $errorClass, $row->provisioning_error, 'Only the step and the class are stored.');
        $this->assertNotNull($row->provisioning_failed_at);
        $this->assertNull($row->provisioning_lease_until, 'A failed run frees the lease.');

        $this->failing = false;
        $this->schemas->stopFailing('createSchema');
        $this->schemas->stopFailing('migrate_settings');
        $this->schemas->stopFailing('migrate_tenant');
        $repository->before('activate', static function (): void {
        });
        User::query()->delete();

        $tenant = $service->provisionReserved($id, 'admin@acme.test', self::SECRET, 'Acme Admin');

        $this->assertSame($id, $tenant->getId());
        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Active, $row->status);
        $this->assertNull($row->provisioning_error, 'A new attempt clears the last failure.');
        $this->assertNull($row->provisioning_failed_at);
        $this->assertNull($row->provisioning_lease_until);
        $this->assertNotNull($row->schema_claimed_at);
        $this->assertSame(1, User::query()->where('email', 'admin@acme.test')->count());
    }

    public function test_a_resumed_run_does_not_duplicate_the_first_admin(): void
    {
        $repository = new FailingTenantRepository();
        $service    = $this->provisioningService($repository);
        $id         = $this->reserve($service, 'acme');

        // The admin exists already: the previous run died after creating it.
        $repository->before('activate', static fn () => throw new RuntimeException('connection lost'));

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
            $this->fail('Expected activation to fail.');
        } catch (TenantProvisioningException) {
            $this->assertSame(1, User::query()->count());
        }

        $repository->before('activate', static function (): void {
        });
        $service->provisionReserved($id, 'admin@acme.test', self::SECRET);

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check(self::SECRET, User::query()->firstOrFail()->password));
    }

    public function test_a_killed_run_is_resumed_once_its_lease_has_run_out(): void
    {
        $service = $this->provisioningService();
        $id      = $this->reserve($service, 'acme');

        // A worker took the lease and the schema marker, then died: no failure mark, a lease in the past.
        Tenant::on('landlord')->whereKey($id)->update([
            'schema_claimed_at'        => CarbonImmutable::now()->subMinutes(20),
            'provisioning_lease_until' => CarbonImmutable::now()->subMinutes(5),
        ]);
        $this->schemas->schemas[] = 'tenant_acme';

        $service->provisionReserved($id, 'admin@acme.test', self::SECRET);

        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($id)->status);
        $this->assertSame(['tenant_acme'], $this->schemas->created, 'The claimed schema is completed, not refused.');
    }

    public function test_a_live_lease_blocks_a_second_run_without_touching_anything(): void
    {
        $service = $this->provisioningService();
        $id      = $this->reserve($service, 'acme');
        $until   = CarbonImmutable::now()->addMinutes(10);
        Tenant::on('landlord')->whereKey($id)->update(['provisioning_lease_until' => $until]);

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
            $this->fail('Expected the run to be refused.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame(ProvisioningProblem::InProgress, $e->problem);
            $this->assertSame($id, $e->tenantId);
        }

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertNull($row->schema_claimed_at);
        $this->assertSame([], $this->schemas->created);
        $this->assertSame($until->getTimestamp(), $row->provisioning_lease_until->getTimestamp(), 'The holder keeps its lease.');
    }

    public function test_the_lease_follows_the_configured_length_and_is_freed_at_the_end(): void
    {
        $now = CarbonImmutable::parse('2026-10-09 12:00:00');
        CarbonImmutable::setTestNow($now);

        $repository = new FailingTenantRepository();
        $seen       = [];
        $repository->before('markSchemaClaimed', function () use (&$seen): void {
            $seen[] = Tenant::on('landlord')->where('slug', 'acme')->firstOrFail()->provisioning_lease_until;
        });
        $service = $this->provisioningService($repository, leaseSeconds: 120);
        $id      = $this->reserve($service, 'acme');

        $service->provisionReserved($id, 'admin@acme.test', self::SECRET);

        $this->assertCount(1, $seen);
        $this->assertSame($now->addSeconds(120)->getTimestamp(), $seen[0]->getTimestamp());
        $this->assertNull(Tenant::on('landlord')->findOrFail($id)->provisioning_lease_until);
    }

    public function test_a_schema_without_the_claim_marker_is_never_adopted(): void
    {
        $service = $this->provisioningService();
        $id      = $this->reserve($service, 'acme');

        // Somebody created tenant_acme after the reservation; this row never claimed it.
        $this->schemas->schemas[] = 'tenant_acme';

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
            $this->fail('Expected the schema to be refused.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame($id, $e->tenantId);
            $previous = $e->getPrevious();
            $this->assertInstanceOf(TenantProvisioningException::class, $previous);
            $this->assertSame(ProvisioningProblem::Conflict, $previous->problem);
        }

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertNull($row->schema_claimed_at);
        $this->assertStringStartsWith('claim_schema: ', (string) $row->provisioning_error);
        $this->assertSame([], $this->schemas->created);
        $this->assertSame([], $this->schemas->migrated);
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_schema_is_claimed_before_it_is_created(): void
    {
        $claimedWhenCreated = null;
        $id                 = null;
        $service            = $this->provisioningService();
        $id                 = $this->reserve($service, 'acme');

        $this->schemas->failOn('createSchema', function () use (&$claimedWhenCreated, $id): void {
            $claimedWhenCreated = null !== Tenant::on('landlord')->findOrFail($id)->schema_claimed_at;

            throw new RuntimeException('killed here');
        });

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
        } catch (TenantProvisioningException) {
        }

        $this->assertTrue($claimedWhenCreated, 'A run killed between claim and CREATE leaves the marker behind.');
    }

    public function test_a_failure_that_cannot_be_recorded_still_reports_the_original_one(): void
    {
        $repository = new FailingTenantRepository();
        $repository->before('markProvisioningFailed', static fn () => throw new RuntimeException('landlord down'));
        $service = $this->provisioningService($repository);
        $id      = $this->reserve($service, 'acme');
        $this->schemas->failOn('createSchema', static fn () => throw new RuntimeException('disk full'));

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
            $this->fail('Expected provisioning to fail.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame('disk full', $e->getPrevious()?->getMessage());
        }
    }

    public function test_a_run_that_lost_its_lease_changes_nothing_and_leaves_the_new_holder_alone(): void
    {
        $repository = new FailingTenantRepository();
        $service    = $this->provisioningService($repository);
        $id         = $this->reserve($service, 'acme');
        $takenOver  = CarbonImmutable::now()->addHours(1);

        // After the schema was created, another run took the lease over (ours had expired).
        $this->schemas->failOn('migrate_settings', static function () use ($id, $takenOver): void {
            Tenant::on('landlord')->whereKey($id)->update(['provisioning_lease_until' => $takenOver]);
        });

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
            $this->fail('Expected the run to stop.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame(ProvisioningProblem::InProgress, $e->problem);
        }

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame($takenOver->getTimestamp(), $row->provisioning_lease_until->getTimestamp(), 'The new holder keeps its lease.');
        $this->assertNull($row->provisioning_error, 'The stale run records no failure.');
    }

    public function test_the_lease_holder_conditions_every_write(): void
    {
        $repository = new TenantRepository();
        $service    = $this->provisioningService($repository);
        $id         = $this->reserve($service, 'acme');
        $now        = CarbonImmutable::now();
        $mine       = $now->addMinutes(15);
        $stale      = $now->addMinutes(1);

        $this->assertTrue($repository->acquireProvisioningLease($id, $now, $mine));
        $this->assertFalse($repository->acquireProvisioningLease($id, $now, $now->addMinutes(30)), 'A live lease cannot be taken.');

        $this->assertFalse($repository->extendProvisioningLease($id, $stale, $now->addMinutes(40)));
        $this->assertFalse($repository->markSchemaClaimed($id, $stale, $now));
        $this->assertFalse($repository->markProvisioningFailed($id, $stale, $now, 'x: y'));
        $this->assertFalse($repository->activate($id, $stale));

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame($mine->getTimestamp(), $row->provisioning_lease_until->getTimestamp());
        $this->assertNull($row->schema_claimed_at);
        $this->assertNull($row->provisioning_error);

        $this->assertTrue($repository->markSchemaClaimed($id, $mine, $now));
        $this->assertTrue($repository->markSchemaClaimed($id, $mine, $now), 'Claiming twice is fine.');
        $this->assertTrue($repository->activate($id, $mine));
        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($id)->status);
    }

    public function test_the_log_carries_the_class_and_code_but_never_the_message(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $service = $this->provisioningService(logger: $logger);
        $id      = $this->reserve($service, 'acme');
        $this->schemas->failOn('createSchema', static fn () => throw new RuntimeException('bindings: ' . self::SECRET));

        try {
            $service->provisionReserved($id, 'admin@acme.test', self::SECRET);
        } catch (TenantProvisioningException) {
        }

        $this->assertNotSame([], $logger->records);
        $this->assertStringNotContainsString(self::SECRET, json_encode($logger->records, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
        $this->assertSame('create_schema', $logger->records[0]['context']['step']);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->expectException(TenantNotFoundException::class);

        $this->provisioningService()->provisionReserved('00000000-0000-0000-0000-0000000000ff', 'a@acme.test', self::SECRET);
    }

    private function reserve(TenantProvisioningService $service, string $slug): string
    {
        return $service->reserveSlug($slug, 'key-' . $slug)->getId();
    }

    private function breakStep(string $step, FailingTenantRepository $repository): void
    {
        match ($step) {
            'create_schema'    => $this->schemas->failOn('createSchema', static fn () => throw new RuntimeException('disk full ' . self::SECRET)),
            'migrate_settings' => $this->schemas->failOn('migrate_settings', static fn () => throw new RuntimeException('lock timeout ' . self::SECRET)),
            'migrate_tenant'   => $this->schemas->failOn('migrate_tenant', static fn () => throw new RuntimeException('syntax error ' . self::SECRET)),
            'acl'              => Role::creating(function (): void {
                if ($this->failing) {
                    throw new RuntimeException('role storage down ' . self::SECRET);
                }
            }),
            'first_admin' => User::factory()->create(['email' => 'someone-else@acme.test']),
            'webhooks'    => null,
            'activate'    => $repository->before('activate', static fn () => throw new RuntimeException('connection lost ' . self::SECRET)),
        };
    }
}
