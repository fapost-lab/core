<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Staff\Services\ApiTokenExpiryNotifier;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Support\TenantHost;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warns every active tenant's administrators about API tokens that expire within 14 days, once per token. Daily, from
 * the scheduler.
 */
final class NotifyExpiringApiTokensCommand extends Command
{
    /** @var string */
    protected $signature = 'api-tokens:notify-expiring';

    /** @var string */
    protected $description = 'Warns tenant administrators about API tokens that expire within 14 days';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now    = CarbonImmutable::now();
        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            try {
                $announced = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(ApiTokenExpiryNotifier::class)
                        ->notifyExpiring($now, TenantHost::urlFor($tenant, '/admin/api-tokens')),
                );

                $this->line("✔ {$tenant->getSlug()}: {$announced}");
            } catch (Throwable $throwable) {
                report($throwable);
                $this->line("✘ {$tenant->getSlug()}: {$throwable->getMessage()}");
                ++$failed;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
