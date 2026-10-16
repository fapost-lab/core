<?php

declare(strict_types=1);

namespace App\Console\Platform;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads every active tenant's channels through {@see TenantSwitcher}, the way the tenant migrations run, and counts
 * those of a type the {@see ChannelRegistryInterface} does not know.
 *
 * A tenant that cannot be read is skipped: the migration step has already reported it, and this check only warns.
 */
final readonly class TenantUnservedChannelsFinder implements UnservedChannelsFinderInterface
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantSwitcher $switcher,
        private ChannelRegistryInterface $registry,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function find(): array
    {
        $found = [];

        foreach ($this->tenants->findAllActive() as $tenant) {
            try {
                /** @var array<string, int> $counts */
                $counts = $this->switcher->runForTenant(
                    $tenant,
                    static fn (): array => Channel::query()
                        ->selectRaw('type, count(*) as total')
                        ->groupBy('type')
                        ->pluck('total', 'type')
                        ->map(static fn (mixed $total): int => (int) $total)
                        ->all(),
                );
            } catch (Throwable $e) {
                // A tenant whose channels cannot be read is reported, not counted as clean.
                Log::warning('platform:update could not check a tenant for channels of unserved types', [
                    'tenant'    => $tenant->getSlug(),
                    'exception' => $e::class,
                ]);

                continue;
            }

            foreach ($counts as $type => $count) {
                if (! $this->registry->has((string) $type)) {
                    $found[] = ['tenant' => $tenant->getSlug(), 'type' => (string) $type, 'count' => $count];
                }
            }
        }

        return $found;
    }
}
