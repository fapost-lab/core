<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2GrantRefresher;
use App\Domains\Credentials\OAuth2\OAuth2GrantRepository;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantAccessStates;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the consent grants of `oauth2_authorization_code` credentials alive: in every active tenant that is not
 * stopped, refreshes the connected grants that have not been refreshed for a week or whose refresh token ends within
 * three days ({@see OAuth2GrantRepository::dueForKeepAlive()}), so a provider that expires an unused refresh token
 * does not leave a flow that runs rarely with a dead credential. A call refreshes an expired access token itself.
 *
 * Each grant is refreshed as a call would, under its lock and read again there ({@see OAuth2GrantRefresher}). A
 * failure of one grant or one tenant is printed (never with a token) and the loop goes on; the exit code is always 0.
 * Wired to the scheduler in {@see routes/console.php}, daily, without overlapping.
 */
final class RefreshOAuth2GrantsCommand extends Command
{
    /** @var string */
    protected $signature = 'credentials:refresh-oauth2-grants';

    /** @var string */
    protected $description = 'Refresh the OAuth2 consent grants that are idle or whose refresh token is about to end';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly TenantAccessStates $accessStates,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                // A stopped tenant runs nothing; its grants are refreshed by the first call after it is active again.
                if ($this->accessStates->stateFor($tenant->getId())->isStopped()) {
                    continue;
                }

                $this->switcher->runForTenant($tenant, function () use ($slug): void {
                    // Resolved inside the tenant: the repository, the store and the refresher are scoped.
                    $grants    = $this->laravel->make(OAuth2GrantRepository::class);
                    $store     = $this->laravel->make(CredentialStoreInterface::class);
                    $refresher = $this->laravel->make(OAuth2GrantRefresher::class);

                    foreach ($grants->dueForKeepAlive() as $grant) {
                        try {
                            $refresher->refreshIfDue(
                                $store->secret($grant->credential_id),
                                static fn (CredentialOAuthGrant $current): bool => OAuth2GrantRepository::keepAliveDue($current),
                            );

                            $this->line("✔ {$slug}: {$grant->credential_id} refreshed");
                        } catch (Throwable $exception) {
                            $this->line("✘ {$slug}: {$grant->credential_id}: {$exception->getMessage()}");
                        }
                    }
                });
            } catch (Throwable $exception) {
                $this->line("✘ {$slug}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
