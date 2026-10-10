<?php

declare(strict_types=1);

namespace App\Console\Commands\Media;

use App\Domains\Media\Services\BlobSizeRecounter;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Corrects stored blob sizes to the real object sizes, so the media storage limit counts true bytes.
 *
 * Run once after deploying the uploader fix that measures the buffer. Safe to repeat.
 */
final class RecountBlobSizesCommand extends Command
{
    /** @var string */
    protected $signature = 'media:recount-blob-sizes
        {--tenant= : Limit the run to one tenant slug}
        {--dry-run : Print the differences without changing anything}';

    /** @var string */
    protected $description = 'Corrects media blob sizes to the size of the stored objects for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(BlobSizeRecounter $recounter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug   = $this->option('tenant');

        if (is_string($slug) && '' !== $slug) {
            $tenant = $this->tenants->findBySlug($slug);

            if (null === $tenant) {
                $this->error("Unknown tenant: {$slug}");

                return self::FAILURE;
            }

            $tenants = [$tenant];
        } else {
            $tenants = $this->tenants->findAllActive();
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $report = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    static fn (): array => $recounter->recount($dryRun),
                );
            } catch (Throwable $throwable) {
                $this->line(sprintf('%s: %s', $tenant->getSlug(), $throwable->getMessage()));
                ++$failed;

                continue;
            }

            foreach ($report['changed'] as $row) {
                $this->line(sprintf(
                    '%s: blob %s %d -> %d bytes%s',
                    $tenant->getSlug(),
                    $row['id'],
                    $row['stored'],
                    $row['actual'],
                    $dryRun ? ' (dry run)' : '',
                ));
            }

            foreach ($report['missing'] as $blobId) {
                $this->line(sprintf('%s: blob %s has no stored object, skipped.', $tenant->getSlug(), $blobId));
            }

            $this->line(sprintf(
                '%s: %d blob(s) checked, %d %s.',
                $tenant->getSlug(),
                $report['checked'],
                count($report['changed']),
                $dryRun ? 'would change' : 'corrected',
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
