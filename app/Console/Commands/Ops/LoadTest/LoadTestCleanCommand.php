<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestStateStore;
use App\Console\Commands\Ops\LoadTest\Support\RefusesProduction;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantDecommissioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes everything `loadtest:seed` created — webhook registry entries,
 * tenant schemas and landlord tenant rows — through
 * {@see TenantDecommissioner}, the Tenancy service that owns tenant removal.
 *
 * Deliberately narrow: Redis keys the run touched otherwise (dedup
 * `processed:*`, per-chat rate limits, message idempotency) all carry their
 * own TTL and self-expire — this command never prefix-scans Redis, which
 * would risk deleting keys a concurrent, unrelated request just wrote.
 */
final class LoadTestCleanCommand extends Command
{
    use RefusesProduction;

    protected $signature = 'loadtest:clean
        {--all : Also sweep any loadtest-* tenant left behind by a previous, incomplete run}';

    protected $description = 'Remove tenants and webhook registry entries created by the load-test harness';

    public function __construct(
        private readonly LoadTestStateStore $state,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantDecommissioner $decommissioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->refuseInProduction()) {
            return self::FAILURE;
        }

        $stateExists = $this->state->exists();
        $sweepAll    = (bool)$this->option('all');

        if (! $stateExists && ! $sweepAll) {
            $this->components->warn('No load-test state found and --all not given — nothing to clean.');

            return self::SUCCESS;
        }

        /** @var array<string, array{id: string, slug: string}> $targets  keyed by tenant id */
        $targets = [];

        if ($stateExists) {
            foreach ($this->state->load()['tenants'] ?? [] as $tenantRow) {
                $targets[(string)$tenantRow['tenant_id']] = [
                    'id'   => (string)$tenantRow['tenant_id'],
                    'slug' => (string)$tenantRow['slug'],
                ];
            }
        }

        if ($sweepAll) {
            foreach ($this->tenants->findBySlugPrefix('loadtest-') as $tenant) {
                $targets[$tenant->getId()] = ['id' => $tenant->getId(), 'slug' => $tenant->getSlug()];
            }
        }

        if ([] === $targets) {
            $this->components->info('Nothing to clean.');
            $this->state->clear();

            return self::SUCCESS;
        }

        $cleaned = 0;
        $failed  = 0;

        foreach ($targets as $target) {
            try {
                $this->cleanTenant($target['id']);
                $cleaned++;
                $this->components->twoColumnDetail($target['slug'], 'cleaned');
            } catch (Throwable $exception) {
                $failed++;
                $this->components->twoColumnDetail($target['slug'], "<error>failed: {$exception->getMessage()}</error>");
            }
        }

        $this->state->clear();

        $this->components->twoColumnDetail('Cleaned', (string)$cleaned);
        $this->components->twoColumnDetail('Failed', (string)$failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function cleanTenant(string $tenantId): void
    {
        $tenant = $this->tenants->findById($tenantId);

        if (null === $tenant) {
            return; // already gone
        }

        $this->decommissioner->decommission($tenant);
    }
}
