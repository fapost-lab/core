<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Infrastructure\CoreTenantReservations;
use App\Domains\Tenancy\Infrastructure\FirstAdminCredentialsGuard;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Contracts\TenantReservationInterface;
use Fapost\Foundation\Tenancy\DTO\TenantAdmin;
use Fapost\Foundation\Tenancy\Enums\ProvisioningFailure;
use Fapost\Foundation\Tenancy\Enums\SlugAvailability;
use Fapost\Foundation\Tenancy\Enums\SlugProblem;
use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Fapost\Foundation\Tenancy\Exceptions\TenantReleaseRefusedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\BuildsProvisioningService;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FailingTenantRepository;

/**
 * The reservation contract against the real landlord table: check, reserve, release and provision
 * by id (AC-01 to AC-05, AC-07 at the contract level).
 */
final class CoreTenantReservationsTest extends FeatureTestCase
{
    use BuildsProvisioningService;

    private const string PASSWORD = 'S3cret-p@ss-value';

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hash = Hash::make(self::PASSWORD);
    }

    /**
     * @return array<string, array{0: string, 1: SlugProblem}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'malformed'     => ['Not A Slug', SlugProblem::Malformed],
            'too long'      => [str_repeat('a', 57), SlugProblem::TooLong],
            'punycode'      => ['xn--acme', SlugProblem::PunycodePrefix],
            'double hyphen' => ['ac--me', SlugProblem::ConsecutiveHyphens],
        ];
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantReservations::class, $this->app->make(TenantReservationInterface::class));
    }

    public function test_check_tells_the_four_availabilities_without_writing(): void
    {
        Tenant::on('landlord')->create(['slug' => 'held', 'schema_name' => 'tenant_held', 'status' => TenantStatus::Pending, 'config' => []]);
        Tenant::on('landlord')->create(['slug' => 'legacy', 'schema_name' => 'tenant_taken_by_legacy', 'status' => TenantStatus::Active, 'config' => []]);
        $count = Tenant::on('landlord')->count();

        $reservations = $this->reservations();

        $this->assertSame(SlugAvailability::Available, $reservations->check('free-slug')->availability);
        $this->assertTrue($reservations->check('free-slug')->isAvailable());
        $this->assertSame(SlugAvailability::Reserved, $reservations->check('webhook')->availability);
        $this->assertSame(SlugAvailability::Taken, $reservations->check('held')->availability, 'A Pending tenant holds its slug.');
        $this->assertSame(SlugAvailability::Taken, $reservations->check('taken-by-legacy')->availability, 'The schema name counts, not only the slug.');

        $this->assertSame($count, Tenant::on('landlord')->count());
    }

    #[DataProvider('invalidSlugs')]
    public function test_check_names_the_problem_of_an_invalid_slug(string $slug, SlugProblem $problem): void
    {
        $check = $this->reservations()->check($slug);

        $this->assertSame(SlugAvailability::Invalid, $check->availability);
        $this->assertSame($problem, $check->problem);
        $this->assertFalse($check->isAvailable());
    }

    public function test_reserve_creates_a_pending_tenant_without_a_schema(): void
    {
        $reserved = $this->reservations()->reserve('acme', 'signup-1');

        $this->assertSame('acme', $reserved->slug);
        $this->assertTrue(Str::isUuid($reserved->id));

        $row = Tenant::on('landlord')->findOrFail($reserved->id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame('tenant_acme', $row->schema_name);
        $this->assertSame('signup-1', $row->reservation_key);
        $this->assertNull($row->schema_claimed_at);
        $this->assertSame([], $this->schemas->created, 'Reserving creates no schema.');
    }

    public function test_the_same_key_returns_the_same_tenant_in_any_status(): void
    {
        $reservations = $this->reservations();
        $first        = $reservations->reserve('acme', 'signup-1');

        $this->assertEquals($first, $reservations->reserve('acme', 'signup-1'));

        Tenant::on('landlord')->whereKey($first->id)->update(['status' => TenantStatus::Active->value]);

        $this->assertEquals($first, $reservations->reserve('acme', 'signup-1'));
        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'acme')->count());
    }

    public function test_a_key_stays_bound_to_its_slug(): void
    {
        $reservations = $this->reservations();
        $reservations->reserve('acme', 'signup-1');

        $this->expectException(InvalidArgumentException::class);

        $reservations->reserve('other', 'signup-1');
    }

    public function test_an_empty_or_over_long_key_is_refused(): void
    {
        foreach (['', str_repeat('k', 65)] as $key) {
            try {
                $this->reservations()->reserve('acme', $key);
                $this->fail('Expected the key to be refused.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Tenant::on('landlord')->where('slug', 'acme')->count());
            }
        }

        $this->reservations()->reserve('acme', str_repeat('k', 64));
        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'acme')->count());
    }

    public function test_reserve_refuses_invalid_reserved_and_taken_slugs(): void
    {
        $reservations = $this->reservations();
        $reservations->reserve('taken', 'signup-1');

        $this->assertReserveFails(ProvisioningFailure::SlugInvalid, 'Not A Slug', 'signup-2');
        $this->assertReserveFails(ProvisioningFailure::SlugReserved, 'webhook', 'signup-3');
        $this->assertReserveFails(ProvisioningFailure::SlugTaken, 'taken', 'signup-4');
        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'taken')->count());
    }

    public function test_an_orphan_schema_makes_the_slug_taken(): void
    {
        $this->provisioningService();
        $this->schemas->schemas[] = 'tenant_orphan';

        $this->assertReserveFails(ProvisioningFailure::SlugTaken, 'orphan', 'signup-1');
        $this->assertSame(0, Tenant::on('landlord')->where('slug', 'orphan')->count());
    }

    public function test_losing_the_insert_race_to_the_same_key_returns_the_winner(): void
    {
        $repository = new FailingTenantRepository();
        $winner     = null;
        $repository->before('reserve', function () use (&$winner): void {
            // A parallel call with the same key got its INSERT in first.
            $winner = Tenant::on('landlord')->create([
                'slug'   => 'acme', 'schema_name' => 'tenant_acme', 'status' => TenantStatus::Pending,
                'config' => [], 'reservation_key' => 'signup-1',
            ]);

            throw new UniqueConstraintViolationException('sqlite', 'insert into tenants', [], new RuntimeException('unique'));
        });

        $reserved = $this->reservations($repository)->reserve('acme', 'signup-1');

        $this->assertSame($winner->id, $reserved->id);
    }

    public function test_losing_the_insert_race_to_another_key_is_slug_taken(): void
    {
        $repository = new FailingTenantRepository();
        $repository->before('reserve', function (): void {
            Tenant::on('landlord')->create([
                'slug'   => 'acme', 'schema_name' => 'tenant_acme', 'status' => TenantStatus::Pending,
                'config' => [], 'reservation_key' => 'someone-else',
            ]);

            throw new UniqueConstraintViolationException('sqlite', 'insert into tenants', [], new RuntimeException('unique'));
        });

        try {
            $this->reservations($repository)->reserve('acme', 'signup-1');
            $this->fail('Expected SlugTaken.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame(ProvisioningFailure::SlugTaken, $e->reason);
        }
    }

    public function test_a_real_unique_violation_inside_the_callers_transaction_still_returns_the_winner(): void
    {
        $repository = new FailingTenantRepository();
        $winner     = null;
        // The competitor commits its INSERT between our checks and our own INSERT.
        $repository->after('isSlugOrSchemaTaken', function () use (&$winner): void {
            $winner = Tenant::on('landlord')->create([
                'slug'   => 'acme', 'schema_name' => 'tenant_acme', 'status' => TenantStatus::Pending,
                'config' => [], 'reservation_key' => 'signup-1',
            ]);
        });

        // FeatureTestCase keeps the landlord connection in a transaction: on PostgreSQL an unguarded
        // violation would abort it and the re-read by key would fail with SQLSTATE 25P02.
        $this->assertGreaterThan(0, DB::connection('landlord')->transactionLevel());

        $reserved = $this->reservations($repository)->reserve('acme', 'signup-1');

        $this->assertSame($winner->id, $reserved->id);
        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'acme')->count());
    }

    public function test_a_real_unique_violation_against_another_key_is_slug_taken(): void
    {
        $repository = new FailingTenantRepository();
        $repository->after('isSlugOrSchemaTaken', static function (): void {
            Tenant::on('landlord')->create([
                'slug'   => 'acme', 'schema_name' => 'tenant_acme', 'status' => TenantStatus::Pending,
                'config' => [], 'reservation_key' => 'someone-else',
            ]);
        });

        try {
            $this->reservations($repository)->reserve('acme', 'signup-1');
            $this->fail('Expected SlugTaken.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame(ProvisioningFailure::SlugTaken, $e->reason);
        }

        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'acme')->count());
    }

    public function test_release_deletes_an_untouched_pending_tenant(): void
    {
        $reserved = $this->reservations()->reserve('acme', 'signup-1');

        $this->reservations()->release($reserved->id);

        $this->assertNull(Tenant::on('landlord')->find($reserved->id));
        $this->assertSame(SlugAvailability::Available, $this->reservations()->check('acme')->availability);
    }

    public function test_release_of_an_unknown_or_malformed_id_is_a_no_op(): void
    {
        $this->reservations()->release((string) Str::uuid());
        $this->reservations()->release('not-an-id');

        $this->addToAssertionCount(1);
    }

    public function test_release_refuses_everything_else_and_deletes_nothing(): void
    {
        $reservations = $this->reservations();

        $active  = $reservations->reserve('active-one', 'k-active');
        $claimed = $reservations->reserve('claimed-one', 'k-claimed');
        $leased  = $reservations->reserve('leased-one', 'k-leased');
        $expired = $reservations->reserve('expired-one', 'k-expired');

        Tenant::on('landlord')->whereKey($active->id)->update(['status' => TenantStatus::Active->value]);
        Tenant::on('landlord')->whereKey($claimed->id)->update(['schema_claimed_at' => CarbonImmutable::now()]);
        Tenant::on('landlord')->whereKey($leased->id)->update(['provisioning_lease_until' => CarbonImmutable::now()->addMinutes(5)]);
        Tenant::on('landlord')->whereKey($expired->id)->update(['provisioning_lease_until' => CarbonImmutable::now()->subMinute()]);

        $this->assertReleaseRefused($active->id, 'is not pending');
        $this->assertReleaseRefused($claimed->id, 'started provisioning');
        $this->assertReleaseRefused($leased->id, 'being provisioned');

        // A lease that ran out does not block: its run is dead.
        $reservations->release($expired->id);

        $this->assertNotNull(Tenant::on('landlord')->find($active->id));
        $this->assertNotNull(Tenant::on('landlord')->find($claimed->id));
        $this->assertNotNull(Tenant::on('landlord')->find($leased->id));
        $this->assertNull(Tenant::on('landlord')->find($expired->id));
    }

    public function test_provision_completes_a_pending_tenant_by_id(): void
    {
        $reservations = $this->reservations();
        $reserved     = $reservations->reserve('acme', 'signup-1');

        $result = $reservations->provision($reserved->id, new TenantAdmin('admin@acme.test', 'Acme Admin', $this->hash));

        $this->assertSame($reserved->id, $result->id);
        $this->assertSame('acme', $result->slug);
        $this->assertSame(url('/admin/login'), $result->loginUrl);
        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($reserved->id)->status);
        $this->assertSame(['tenant_acme'], $this->schemas->created);
        $this->assertSame('Acme Admin', User::query()->where('email', 'admin@acme.test')->firstOrFail()->name);
    }

    public function test_provision_on_an_active_tenant_returns_at_once(): void
    {
        $reservations = $this->reservations();
        $reserved     = $reservations->reserve('acme', 'signup-1');
        $admin        = new TenantAdmin('admin@acme.test', 'Acme Admin', $this->hash);

        $reservations->provision($reserved->id, $admin);
        $schemasCreated = $this->schemas->created;

        $again = $reservations->provision($reserved->id, $admin);

        $this->assertSame($reserved->id, $again->id);
        $this->assertSame($schemasCreated, $this->schemas->created, 'Nothing is done twice.');
        $this->assertSame(1, User::query()->count());
    }

    public function test_provision_refuses_a_missing_or_inactive_tenant_and_a_live_lease(): void
    {
        $reservations = $this->reservations();
        $admin        = new TenantAdmin('admin@acme.test', 'Acme Admin', $this->hash);

        $inactive = $reservations->reserve('off', 'k-off');
        Tenant::on('landlord')->whereKey($inactive->id)->update(['status' => TenantStatus::Inactive->value]);
        $busy = $reservations->reserve('busy', 'k-busy');
        Tenant::on('landlord')->whereKey($busy->id)->update(['provisioning_lease_until' => CarbonImmutable::now()->addMinutes(5)]);

        $this->assertProvisionFails(ProvisioningFailure::TenantNotFound, (string) Str::uuid(), $admin);
        $this->assertProvisionFails(ProvisioningFailure::TenantNotFound, 'not-an-id', $admin);
        $this->assertProvisionFails(ProvisioningFailure::TenantNotPending, $inactive->id, $admin);

        $e = $this->assertProvisionFails(ProvisioningFailure::InProgress, $busy->id, $admin);
        $this->assertSame($busy->id, $e->tenantId);
        $this->assertTrue($e->reason->isRetryable());
        $this->assertSame([], $this->schemas->created);
    }

    public function test_provision_validates_the_administrator(): void
    {
        $reserved = $this->reservations()->reserve('acme', 'signup-1');

        foreach ([
            [ProvisioningFailure::AdminCredentialsMissing, new TenantAdmin(' ', 'A', $this->hash)],
            [ProvisioningFailure::AdminCredentialsMissing, new TenantAdmin('a@acme.test', 'A', '')],
            [ProvisioningFailure::AdminPasswordHashInvalid, new TenantAdmin('a@acme.test', 'A', self::PASSWORD)],
        ] as [$reason, $admin]) {
            $e = $this->assertProvisionFails($reason, $reserved->id, $admin);
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }

        $this->assertSame(TenantStatus::Pending, Tenant::on('landlord')->findOrFail($reserved->id)->status);
    }

    public function test_a_failure_leaves_the_tenant_pending_and_provision_can_be_repeated(): void
    {
        $reservations = $this->reservations();
        $reserved     = $reservations->reserve('acme', 'signup-1');
        $admin        = new TenantAdmin('admin@acme.test', 'Acme Admin', $this->hash);

        $this->schemas->failOn('createSchema', static fn () => throw new RuntimeException('disk full'));

        $e = $this->assertProvisionFails(ProvisioningFailure::Failed, $reserved->id, $admin);
        $this->assertSame($reserved->id, $e->tenantId);
        $this->assertTrue($e->reason->isRetryable());
        $this->assertFalse($e->reason->isInputError());
        $this->assertSame(TenantStatus::Pending, Tenant::on('landlord')->findOrFail($reserved->id)->status);

        $this->schemas->stopFailing('createSchema');

        $this->assertSame($reserved->id, $reservations->provision($reserved->id, $admin)->id);
        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($reserved->id)->status);
    }

    private function reservations(?TenantRepositoryInterface $repository = null): CoreTenantReservations
    {
        $repository ??= new TenantRepository();
        $service = $this->provisioningService($repository);

        return new CoreTenantReservations(
            $service,
            $repository,
            new TenantSlugPolicy(['webhook', 'www', 'api']),
            new FirstAdminCredentialsGuard($this->app->make(HashManager::class)),
        );
    }

    private function assertReserveFails(ProvisioningFailure $reason, string $slug, string $key): void
    {
        try {
            $this->reservations()->reserve($slug, $key);
            $this->fail('Expected reserve to fail.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function assertReleaseRefused(string $id, string $messagePart): void
    {
        try {
            $this->reservations()->release($id);
            $this->fail('Expected release to be refused.');
        } catch (TenantReleaseRefusedException $e) {
            $this->assertSame($id, $e->tenantId);
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    private function assertProvisionFails(ProvisioningFailure $reason, string $id, TenantAdmin $admin): TenantProvisioningFailedException
    {
        try {
            $this->reservations()->provision($id, $admin);
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail('Expected provision to fail.');
    }
}
