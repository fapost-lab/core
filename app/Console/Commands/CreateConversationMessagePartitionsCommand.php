<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Keeps every tenant's conversation_messages partitions ahead of the writes.
 *
 * `conversation_messages` lives in the tenant schema, so its partitions are created inside tenant context. The current
 * and the next month are ensured, in UTC as the store writes them: run daily, next month's partition exists weeks
 * before its first message, and the current one heals a schema whose scheduler went quiet. The store still ensures
 * the partition on write as a safety net. Idempotent: an existing partition is left as it is.
 */
final class CreateConversationMessagePartitionsCommand extends Command
{
    /** @var string */
    protected $signature = 'conversations:create-partition';

    /** @var string */
    protected $description = 'Creates current and next month conversation_messages partitions for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // startOfMonth() before addMonth(): on the 31st, addMonth() alone would overflow past next month.
        $currentMonth = CarbonImmutable::now('UTC')->startOfMonth();
        $months       = [$currentMonth, $currentMonth->addMonth()];

        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                /** @var list<string> $created */
                $created = $this->tenantSwitcher->runForTenant($tenant, function () use ($months): array {
                    // The manager resolves its connection on every call, so it follows the switch to this tenant's schema.
                    $partitions = new ConversationPartitionManager($this->database);

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
