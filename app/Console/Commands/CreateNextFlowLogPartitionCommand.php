<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Flow\Logging\FlowLogPartitionManager;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Keeps every tenant's flow_logs partitions ahead of the writes.
 *
 * `flow_logs` lives in the tenant schema, so partitions have to be created
 * inside tenant context — running against the default connection alone targets
 * a schema that has no flow_logs at all.
 *
 * Both the current and the next month are ensured: the current one heals a
 * schema created (or a scheduler that went quiet) mid-month, which otherwise
 * leaves every flow-log insert failing until the 1st.
 */
final class CreateNextFlowLogPartitionCommand extends Command
{
    /** @var string */
    protected $signature = 'logs:create-partition';

    /** @var string */
    protected $description = 'Creates current and next month flow_logs partitions for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $months = [
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->addMonth()->startOfMonth(),
        ];

        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                /** @var list<string> $created */
                $created = $this->tenantSwitcher->runForTenant($tenant, function () use ($months): array {
                    // Built inside the switch: switching tenants purges and
                    // rebuilds the default connection, so a manager captured
                    // beforehand would still be pointing at the old schema.
                    $partitions = new FlowLogPartitionManager($this->database->connection());

                    $names = [];
                    foreach ($months as $month) {
                        $partitions->ensureMonthlyPartition($month);
                        $names[] = $partitions->partitionName($month);
                    }

                    return $names;
                });

                $this->line("✔ {$slug}: " . implode(', ', $created));
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
