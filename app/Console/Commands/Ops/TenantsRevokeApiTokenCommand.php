<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Staff\Enums\OperatorRevokeOutcome;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The installation operator's incident revoke of a leaked tenant API token (`adr-20261119-tenant-api-tokens` §8).
 *
 * The operator gives the leaked token itself, never an id: the row is found in the tenant's schema by the id the token
 * carries and revoked only if the secret matches, so nothing is learned and nothing can be revoked by guessing. The
 * token is read from a hidden prompt or, without a terminal, from standard input, never from an argument (shell
 * history, process lists). The revocation is recorded in the tenant's audit log as platform support, named after the
 * operator. The operator can do nothing else with a tenant's tokens.
 */
final class TenantsRevokeApiTokenCommand extends Command
{
    /** @var string */
    protected $signature = 'tenants:api-token:revoke
        {tenant : Slug or id of the tenant}
        {--operator= : Your name, recorded in the tenant\'s audit log}';

    /** @var string */
    protected $description = 'Revoke a leaked API token of a tenant (the token is read from a prompt or standard input)';

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): int
    {
        $operator = mb_trim((string) $this->option('operator'));

        if ('' === $operator) {
            $this->components->error('Name yourself with --operator: the revocation is recorded in the tenant\'s audit log.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('tenant');
        $tenant    = $tenants->findBySlug($reference) ?? (Str::isUuid($reference) ? $tenants->findById(mb_strtolower($reference)) : null);

        if (! $tenant instanceof TenantInterface) {
            $this->components->error(sprintf('No tenant "%s".', $reference));

            return self::FAILURE;
        }

        $plain = $this->input->isInteractive()
            ? (string) $this->secret('The leaked token')
            : (string) stream_get_contents(STDIN);

        $outcome = $switcher->runForTenant(
            $tenant,
            fn (): OperatorRevokeOutcome => $this->laravel->make(ApiTokenService::class)->revokeByOperator(mb_trim($plain), $operator),
        );

        return match ($outcome) {
            OperatorRevokeOutcome::Revoked        => $this->done(sprintf('The token is revoked in "%s".', $tenant->getSlug())),
            OperatorRevokeOutcome::AlreadyRevoked => $this->done(sprintf('The token was already revoked in "%s".', $tenant->getSlug())),
            OperatorRevokeOutcome::NotFound       => $this->notFound($tenant),
        };
    }

    private function done(string $message): int
    {
        $this->components->info($message);

        return self::SUCCESS;
    }

    private function notFound(TenantInterface $tenant): int
    {
        $this->components->error(sprintf('This is not an API token of "%s".', $tenant->getSlug()));

        return self::FAILURE;
    }
}
