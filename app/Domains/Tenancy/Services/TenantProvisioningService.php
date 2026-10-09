<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Exceptions\FirstAdminConflictException;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use App\Domains\Tenancy\ValueObjects\ProvisioningLease;
use App\Domains\Tenancy\ValueObjects\ProvisioningProblem;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Tenancy\ValueObjects\TenantProvisioningState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Reserves a tenant's slug and builds the tenant: landlord record, PostgreSQL schema, tenant
 * migrations and first staff admin.
 *
 * Two phases, each safe to repeat. {@see reserveSlug()} inserts a Pending row that owns the slug and
 * the schema name and creates nothing else. {@see provisionReserved()} completes that row by id and
 * resumes a run that failed or was killed.
 *
 * Resuming is possible because of three things kept in the landlord row, since a transaction cannot
 * span the landlord and tenant connections: a lease (one run at a time), a schema-claim marker set
 * before CREATE SCHEMA (a schema is adopted only when this row claimed it) and steps that tolerate
 * being repeated.
 */
final readonly class TenantProvisioningService
{
    private const string DEFAULT_ADMIN_NAME = 'Administrator';

    private const int MAX_RESERVATION_KEY_LENGTH = 64;

    public function __construct(
        private TenantRepositoryInterface $tenantRepository,
        private TenantDatabaseManagerInterface $databaseManager,
        private TenantSwitcher $tenantSwitcher,
        private AclBootstrapService $aclBootstrapService,
        private ChannelWebhookRegistryInterface $channelWebhookRegistry,
        private TenantSlugPolicy $slugPolicy,
        private LoggerInterface $logger,
        private int $leaseSeconds = 900,
    ) {
    }

    /**
     * Provisions a tenant in one go: reserves the slug without a key, then completes the reservation.
     *
     * Guarantees:
     * - Status becomes active only after the first admin user exists in the tenant schema with the admin role.
     * - Migration and user creation run inside {@see TenantSwitcher::runForTenant()} (tenant DB + context).
     * - On failure after the row exists, the row stays Pending and the exception names it
     *   ({@see TenantProvisioningException::$tenantId}); {@see provisionReserved()} continues it.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidTenantSlugException  when the slug is malformed, too long or reserved by the platform.
     * @throws TenantProvisioningException  (SlugTaken) when the slug or its schema name is already held.
     * @throws TenantProvisioningException on missing admin credentials, or any step failure.
     */
    public function provision(
        string $slug,
        string $firstAdminEmail,
        #[SensitiveParameter]
        string $firstAdminPassword,
        string $firstAdminName = self::DEFAULT_ADMIN_NAME,
        array $config = [],
    ): TenantInterface {
        // Checked before anything is created: the slug determines both the schema
        // name and, on a subdomain deployment, a hostname the tenant would control.
        $this->slugPolicy->assertAssignable($slug);

        if ('' === $firstAdminPassword || '' === mb_trim($firstAdminEmail)) {
            throw TenantProvisioningException::credentialsMissing();
        }

        $tenant = $this->reserveSlug($slug, null, $config);

        return $this->provisionReserved($tenant->getId(), $firstAdminEmail, $firstAdminPassword, $firstAdminName);
    }

    /**
     * Inserts a Pending tenant holding the slug and its schema name. No schema is created.
     *
     * With a reservation key the call is idempotent: the tenant already reserved under the key is
     * returned, in any status. A key stays bound to the slug it first reserved.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException   when the key is empty, too long, or bound to another slug.
     * @throws InvalidTenantSlugException when the slug is malformed, too long or reserved by the platform.
     * @throws TenantProvisioningException (SlugTaken) when the slug or its schema name is held, or a schema of that name exists without a tenant.
     */
    public function reserveSlug(string $slug, ?string $reservationKey = null, array $config = []): TenantInterface
    {
        if (null !== $reservationKey) {
            $this->assertValidKey($reservationKey);

            $existing = $this->tenantRepository->findByReservationKey($reservationKey);

            if ($existing instanceof TenantInterface) {
                return $this->sameSlugOrFail($existing, $slug);
            }
        }

        $this->slugPolicy->assertAssignable($slug);

        $schemaName = $this->slugPolicy->schemaNameFor($slug);

        if ($this->tenantRepository->isSlugOrSchemaTaken($slug, $schemaName)) {
            throw TenantProvisioningException::slugTaken($slug);
        }

        // A schema nobody owns: whose data it holds is unknown, so it is never adopted.
        if ($this->databaseManager->schemaExists(new RuntimeTenant('', $schemaName, $slug, false))) {
            $this->logger->warning('Tenant slug cannot be reserved: an orphan schema exists.', [
                'slug'        => $slug,
                'schema_name' => $schemaName,
            ]);

            throw TenantProvisioningException::orphanSchema($slug, $schemaName);
        }

        $tenant = new Tenant([
            'slug'            => $slug,
            'schema_name'     => $schemaName,
            'status'          => TenantStatus::Pending,
            'config'          => $config,
            'reservation_key' => $reservationKey,
        ]);

        try {
            $this->tenantRepository->reserve($tenant);
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race on the slug, the schema name or the key.
            $existing = null === $reservationKey ? null : $this->tenantRepository->findByReservationKey($reservationKey);

            if ($existing instanceof TenantInterface) {
                return $this->sameSlugOrFail($existing, $slug);
            }

            throw TenantProvisioningException::slugTaken($slug, $exception);
        }

        return $tenant;
    }

    /**
     * Deletes a Pending tenant whose provisioning never claimed a schema.
     *
     * One conditional DELETE, against the conditional UPDATE that takes the provisioning lease: the
     * database decides which of a release and a provisioning run wins. An unknown id is a no-op.
     *
     * @return ReleaseRefusal|null null when the row was deleted or does not exist
     */
    public function releaseReservation(string $tenantId): ?ReleaseRefusal
    {
        return $this->tenantRepository->releaseUnclaimedPending($tenantId, CarbonImmutable::now());
    }

    /**
     * Completes a Pending tenant: schema, migrations, ACL, first admin, webhooks, then activation.
     *
     * Safe to repeat: after a failure or a killed run it continues, and on an Active tenant it
     * returns at once. The row stays Pending on any failure, with the failed step recorded.
     *
     * @throws TenantNotFoundException     when no row has this id.
     * @throws TenantProvisioningException NotPending (inactive or suspended), InProgress (another run holds the lease) or a failed step;
     *                                     {@see TenantProvisioningException::$tenantId} names the Pending tenant that remains.
     */
    public function provisionReserved(
        string $tenantId,
        string $firstAdminEmail,
        #[SensitiveParameter]
        string $firstAdminPassword,
        string $firstAdminName = self::DEFAULT_ADMIN_NAME,
    ): TenantInterface {
        if ('' === $firstAdminPassword || '' === mb_trim($firstAdminEmail)) {
            throw TenantProvisioningException::credentialsMissing($tenantId);
        }

        $state = $this->tenantRepository->provisioningState($tenantId) ?? throw TenantNotFoundException::forId($tenantId);

        if (TenantStatus::Active === $state->status) {
            return $state->tenant;
        }

        $this->assertPending($state);

        $now   = CarbonImmutable::now();
        $lease = new ProvisioningLease($this->leaseEnd($now));

        if (! $this->tenantRepository->acquireProvisioningLease($tenantId, $now, $lease->until)) {
            $state = $this->tenantRepository->provisioningState($tenantId) ?? throw TenantNotFoundException::forId($tenantId);

            if (TenantStatus::Active === $state->status) {
                return $state->tenant;
            }

            $this->assertPending($state);

            throw TenantProvisioningException::inProgress($tenantId, $state->tenant->getSlug());
        }

        // Re-read under the lease: only its holder sets the claim marker.
        $state  = $this->tenantRepository->provisioningState($tenantId) ?? throw TenantNotFoundException::forId($tenantId);
        $tenant = $state->tenant;
        $step   = 'claim_schema';

        try {
            $this->claimSchema($state, $lease);

            $step = 'create_schema';
            $this->databaseManager->createSchema($tenant);
            $this->renewLease($tenant, $lease);

            $this->tenantSwitcher->runForTenant($tenant, function () use (
                $lease,
                $tenant,
                $firstAdminEmail,
                $firstAdminPassword,
                $firstAdminName,
                &$step,
            ): void {
                $step = 'migrate_settings';
                $this->databaseManager->runMigrations(MigrationScope::settings());
                $this->renewLease($tenant, $lease);

                $step = 'migrate_tenant';
                $this->databaseManager->runMigrations(MigrationScope::tenant());
                $this->renewLease($tenant, $lease);

                $step = 'acl';
                $this->aclBootstrapService->bootstrap();

                $step = 'first_admin';
                $this->aclBootstrapService->createFirstAdmin($firstAdminEmail, $firstAdminPassword, $firstAdminName);
                $this->renewLease($tenant, $lease);

                $step = 'webhooks';
                $this->channelWebhookRegistry->warmup($tenant);
            });

            $step = 'activate';
            $this->activate($tenantId, $lease);
        } catch (Throwable $throwable) {
            if ($throwable instanceof TenantProvisioningException && ProvisioningProblem::InProgress === $throwable->problem) {
                // The lease went to another run, which owns the row now: record nothing, change nothing.
                throw $throwable;
            }

            $this->recordFailure($tenant, $lease, $step, $throwable);

            throw $this->isConflict($throwable)
                ? TenantProvisioningException::conflict($tenantId, $tenant->getSlug(), $step, $throwable)
                : TenantProvisioningException::stepFailed($tenantId, $tenant->getSlug(), $step, $throwable);
        }

        return $this->tenantRepository->getById($tenantId);
    }

    private function claimSchema(TenantProvisioningState $state, ProvisioningLease $lease): void
    {
        if ($state->schemaClaimed) {
            return;
        }

        if ($this->databaseManager->schemaExists($state->tenant)) {
            throw TenantProvisioningException::schemaConflict(
                $state->tenant->getId(),
                $state->tenant->getSlug(),
                $state->tenant->getSchemaName(),
            );
        }

        // Before CREATE SCHEMA: a run killed between the two finds the marker and finishes the job,
        // while a schema without the marker is somebody else's.
        if (! $this->tenantRepository->markSchemaClaimed($state->tenant->getId(), $lease->until, CarbonImmutable::now())) {
            throw TenantProvisioningException::leaseLost($state->tenant->getId(), $state->tenant->getSlug());
        }
    }

    private function activate(string $tenantId, ProvisioningLease $lease): void
    {
        if ($this->tenantRepository->activate($tenantId, $lease->until)) {
            return;
        }

        $state = $this->tenantRepository->provisioningState($tenantId);

        if (null === $state) {
            throw TenantProvisioningException::notActivated($tenantId);
        }

        // Another run took the lease over and finished the job: nothing left to do.
        if (TenantStatus::Active !== $state->status) {
            throw TenantProvisioningException::leaseLost($tenantId, $state->tenant->getSlug());
        }
    }

    private function isConflict(Throwable $throwable): bool
    {
        return $throwable instanceof FirstAdminConflictException
            || ($throwable instanceof TenantProvisioningException && ProvisioningProblem::Conflict === $throwable->problem);
    }

    private function assertPending(TenantProvisioningState $state): void
    {
        if (TenantStatus::Pending !== $state->status) {
            throw TenantProvisioningException::notPending($state->tenant->getId(), $state->tenant->getSlug());
        }
    }

    /**
     * @throws TenantProvisioningException when another run has taken the lease over
     */
    private function renewLease(TenantInterface $tenant, ProvisioningLease $lease): void
    {
        $until = $this->leaseEnd(CarbonImmutable::now());

        if (! $this->tenantRepository->extendProvisioningLease($tenant->getId(), $lease->until, $until)) {
            throw TenantProvisioningException::leaseLost($tenant->getId(), $tenant->getSlug());
        }

        $lease->until = $until;
    }

    private function leaseEnd(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addSeconds($this->leaseSeconds);
    }

    /**
     * Only the step and the exception class reach the landlord, and the log gets the class, its code
     * (the SQLSTATE of a database error) and the step: the message and bindings of a database exception
     * may carry a password hash.
     */
    private function recordFailure(TenantInterface $tenant, ProvisioningLease $lease, string $step, Throwable $throwable): void
    {
        $this->logger->error('Tenant provisioning step failed.', [
            'tenant_id'       => $tenant->getId(),
            'slug'            => $tenant->getSlug(),
            'step'            => $step,
            'exception_class' => $throwable::class,
            'code'            => $throwable instanceof QueryException ? $throwable->getPrevious()?->getCode() : $throwable->getCode(),
        ]);

        try {
            $this->tenantRepository->markProvisioningFailed(
                $tenant->getId(),
                $lease->until,
                CarbonImmutable::now(),
                $step . ': ' . $throwable::class,
            );
        } catch (Throwable $recording) {
            // The lease expires on its own; the original failure is the one to report.
            $this->logger->error('Could not record the provisioning failure.', [
                'tenant_id'       => $tenant->getId(),
                'exception_class' => $recording::class,
            ]);
        }
    }

    private function assertValidKey(string $reservationKey): void
    {
        if ('' === $reservationKey || mb_strlen($reservationKey) > self::MAX_RESERVATION_KEY_LENGTH) {
            throw new InvalidArgumentException(
                'The reservation key must be 1 to ' . self::MAX_RESERVATION_KEY_LENGTH . ' characters.',
            );
        }
    }

    private function sameSlugOrFail(TenantInterface $existing, string $slug): TenantInterface
    {
        if ($existing->getSlug() !== $slug) {
            throw new InvalidArgumentException('The reservation key is already bound to another slug.');
        }

        return $existing;
    }
}
