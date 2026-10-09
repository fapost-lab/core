<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\ValueObjects\ProvisioningProblem;
use RuntimeException;
use Throwable;

/**
 * Provisioning did not complete. When a row was already reserved, {@see $tenantId} names the Pending
 * tenant that remains and {@see \App\Domains\Tenancy\Services\TenantProvisioningService::provisionReserved()}
 * continues it.
 */
final class TenantProvisioningException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ProvisioningProblem $problem,
        public readonly ?string $tenantId = null,
        public readonly ?string $slug = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function credentialsMissing(?string $tenantId = null): self
    {
        return new self('First admin email and password are required.', ProvisioningProblem::CredentialsMissing, $tenantId);
    }

    public static function slugTaken(string $slug, ?Throwable $previous = null): self
    {
        return new self("Tenant slug [{$slug}] is already taken.", ProvisioningProblem::SlugTaken, null, $slug, $previous);
    }

    public static function orphanSchema(string $slug, string $schemaName): self
    {
        return new self(
            "Schema [{$schemaName}] already exists without a tenant; slug [{$slug}] cannot be reserved.",
            ProvisioningProblem::SlugTaken,
            null,
            $slug,
        );
    }

    public static function notPending(string $tenantId, string $slug): self
    {
        return new self("Tenant [{$slug}] is not pending and cannot be provisioned.", ProvisioningProblem::NotPending, null, $slug);
    }

    public static function inProgress(string $tenantId, string $slug): self
    {
        return new self("Tenant [{$slug}] is already being provisioned.", ProvisioningProblem::InProgress, $tenantId, $slug);
    }

    /**
     * A schema with the tenant's name exists although the tenant never claimed it. It is never adopted:
     * whose data it holds is unknown, and repeating provisioning cannot help.
     */
    public static function schemaConflict(string $tenantId, string $slug, string $schemaName): self
    {
        return new self(
            "Schema {$schemaName} exists but was never claimed by tenant {$slug}.",
            ProvisioningProblem::Conflict,
            $tenantId,
            $slug,
        );
    }

    /**
     * The message names the step and the class only: the previous exception may carry query bindings,
     * a password hash among them. It stays in the `previous` chain for whoever must read it.
     */
    public static function stepFailed(string $tenantId, string $slug, string $step, Throwable $previous): self
    {
        $class = $previous::class;

        return new self(
            "Failed to provision tenant {$slug} at step {$step} ({$class}).",
            ProvisioningProblem::StepFailed,
            $tenantId,
            $slug,
            $previous,
        );
    }

    /**
     * Provisioning cannot complete without an operator (see {@see ProvisioningProblem::Conflict}).
     */
    public static function conflict(string $tenantId, string $slug, string $step, Throwable $previous): self
    {
        return new self(
            "Tenant {$slug} cannot be provisioned at step {$step}: {$previous->getMessage()}",
            ProvisioningProblem::Conflict,
            $tenantId,
            $slug,
            $previous,
        );
    }

    public static function leaseLost(string $tenantId, string $slug): self
    {
        return new self("Provisioning of tenant {$slug} lost its lease to another run.", ProvisioningProblem::InProgress, $tenantId, $slug);
    }

    public static function notActivated(string $tenantId): self
    {
        return new self('The tenant could not be activated.', ProvisioningProblem::StepFailed, $tenantId);
    }
}
