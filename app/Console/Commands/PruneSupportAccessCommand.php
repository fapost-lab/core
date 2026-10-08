<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Staff\Services\SupportAccessEntryService;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Housekeeping for support access: removes tokens that expired long ago (a token is useless after a
 * minute; rows are kept a day to trace a failed entry) and closes entries whose session ended without
 * a sign-out, recording them as left when their hour was up.
 */
final class PruneSupportAccessCommand extends Command
{
    /** @var string */
    protected $signature = 'support-access:prune';

    /** @var string */
    protected $description = 'Deletes expired support access tokens and closes abandoned support access entries';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(SupportAccessTokenStore $tokens): int
    {
        $this->info(sprintf('Removed %d expired support access token(s).', $tokens->prune()));

        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            try {
                $closed = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(SupportAccessEntryService::class)->closeAbandoned(),
                );

                if ($closed > 0) {
                    $this->line(sprintf('%s: closed %d abandoned entr%s.', $tenant->getSlug(), $closed, 1 === $closed ? 'y' : 'ies'));
                }
            } catch (Throwable $throwable) {
                $this->line(sprintf('%s: %s', $tenant->getSlug(), $throwable->getMessage()));
                ++$failed;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
