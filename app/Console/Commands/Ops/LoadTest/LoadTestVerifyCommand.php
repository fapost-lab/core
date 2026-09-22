<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestFlowBlueprint;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestSessionVerifier;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestStateStore;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestVerificationReport;
use App\Console\Commands\Ops\LoadTest\Support\RefusesProduction;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks the outcome of a `loadtest:run` against the plan `loadtest:seed`
 * wrote: every contact ended its session, nobody's code ended up on someone
 * else's contact or in someone else's tenant schema, and no job failed.
 *
 * A contact whose message was dropped under lock contention
 * ({@see \App\Domains\Flow\Routing\RoutingOutcome::dropped()} with reason
 * `lock_timeout`) is expected under real concurrency — {@see DropPolicy}
 * already told that contact the bot was busy. That is reported as a metric,
 * not a failure, unless it crosses {@code --max-dropped}. A leak — another
 * contact's or tenant's code showing up where it should not — always fails.
 */
final class LoadTestVerifyCommand extends Command
{
    use RefusesProduction;

    protected $signature = 'loadtest:verify
        {--max-dropped=5 : Fail only when the dropped-contact ratio exceeds this percentage}';

    protected $description = 'Verify a load-test run for leaks, drops and failed jobs';

    public function __construct(
        private readonly LoadTestStateStore $state,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly LoadTestSessionVerifier $verifier,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->refuseInProduction()) {
            return self::FAILURE;
        }

        $state = $this->state->load();

        /** @var list<array{tenant_slug: string, chat_id: int, code: string}> $expected */
        $expected = array_map(
            static fn (array $row): array => [
                'tenant_slug' => (string)$row['tenant_slug'],
                'chat_id'     => (int)$row['chat_id'],
                'code'        => (string)$row['code'],
            ],
            $state['contacts'] ?? [],
        );

        if ([] === $expected) {
            $this->components->warn('No contacts in state — nothing to verify.');

            return self::SUCCESS;
        }

        $maxDroppedRatio = max(0.0, (float)$this->option('max-dropped')) / 100;

        [$actualCodesByChatId, $sessionStatusCounts, $tenantBreaches] = $this->collectFromTenants($state, $expected);

        $report       = $this->verifier->verify($expected, $actualCodesByChatId);
        $stubLog      = $this->readStubLog((string)($state['stub_log_path'] ?? ''));
        $stubLeaks    = $this->verifier->verifyStubMessages($expected, $stubLog);
        $otherReplies = $this->countOtherReplies($expected, $stubLog);
        $failedJobs   = $this->countFailedJobsSince($state);
        $rateLimit    = (int)$this->config->get('messaging.rate_limit_per_minute', 30);

        $this->printReport($report, $stubLeaks, $otherReplies, $tenantBreaches, $sessionStatusCounts, $failedJobs, $rateLimit, $maxDroppedRatio);

        $hardFailure = $report->hasLeaks()
            || $report->hasCorruption()
            || [] !== $stubLeaks
            || [] !== $tenantBreaches
            || $failedJobs > 0
            || $report->droppedRatio() > $maxDroppedRatio;

        if ($hardFailure) {
            $this->components->error('Load test verification FAILED.');

            return self::FAILURE;
        }

        $this->components->info('Load test verification passed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array{tenant_slug: string, chat_id: int, code: string}>  $expected
     *
     * @return array{0: array<int, string|null>, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    private function collectFromTenants(array $state, array $expected): array
    {
        $expectedByTenant = [];
        foreach ($expected as $row) {
            $expectedByTenant[$row['tenant_slug']][] = $row['chat_id'];
        }

        $actualCodesByChatId = [];
        $sessionStatusCounts = [];
        $breaches            = [];

        foreach ($state['tenants'] ?? [] as $tenantRow) {
            $chatIds = $expectedByTenant[$tenantRow['slug']] ?? [];
            if ([] === $chatIds) {
                continue;
            }

            try {
                $tenant = $this->tenantRepository->getById((string)$tenantRow['tenant_id']);
            } catch (Throwable $exception) {
                $this->components->warn("Tenant {$tenantRow['slug']} no longer exists: {$exception->getMessage()}");

                continue;
            }

            $this->tenantSwitcher->runForTenant(
                $tenant,
                function () use ($tenant, $tenantRow, $chatIds, &$actualCodesByChatId, &$sessionStatusCounts, &$breaches): void {
                    $externalIds = array_map(strval(...), $chatIds);

                    $contacts = Contact::query()
                        ->where('platform', PlatformEnum::Telegram->value)
                        ->whereIn('external_id', $externalIds)
                        ->get(['id', 'external_id', 'attributes', 'tenant_id']);

                    foreach ($contacts as $contact) {
                        $chatId                       = (int)$contact->external_id;
                        $code                         = is_array($contact->attributes) ? ($contact->attributes[LoadTestFlowBlueprint::ATTRIBUTE_NAME] ?? null) : null;
                        $actualCodesByChatId[$chatId] = is_string($code) ? $code : null;

                        if ((string)$contact->tenant_id !== $tenant->getId()) {
                            $breaches[] = [
                                'kind'               => 'contact',
                                'schema'             => $tenantRow['slug'],
                                'row_tenant_id'      => (string)$contact->tenant_id,
                                'expected_tenant_id' => $tenant->getId(),
                            ];
                        }
                    }

                    if ($contacts->isEmpty()) {
                        return;
                    }

                    $sessions = FlowSession::query()
                        ->whereIn('contact_id', $contacts->pluck('id'))
                        ->get(['id', 'contact_id', 'status', 'tenant_id']);

                    foreach ($sessions as $session) {
                        $status                       = $session->status->value;
                        $sessionStatusCounts[$status] = ($sessionStatusCounts[$status] ?? 0) + 1;

                        if ((string)$session->tenant_id !== $tenant->getId()) {
                            $breaches[] = [
                                'kind'               => 'flow_session',
                                'schema'             => $tenantRow['slug'],
                                'row_tenant_id'      => (string)$session->tenant_id,
                                'expected_tenant_id' => $tenant->getId(),
                            ];
                        }
                    }
                },
            );
        }

        return [$actualCodesByChatId, $sessionStatusCounts, $breaches];
    }

    /**
     * @return list<array{chat_id: int, text: string, ts: float}>
     */
    private function readStubLog(string $logPath): array
    {
        if ('' === $logPath || ! is_file($logPath)) {
            return [];
        }

        $entries = [];

        foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['chat_id'], $decoded['text'])) {
                $entries[] = [
                    'chat_id' => (int)$decoded['chat_id'],
                    'text'    => (string)$decoded['text'],
                    'ts'      => (float)($decoded['ts'] ?? 0),
                ];
            }
        }

        return $entries;
    }

    /**
     * Outbound stub messages that are neither the known prompt nor a confirm
     * for their own chat — almost always {@see DropPolicy}'s busy notice.
     *
     * @param  list<array{tenant_slug: string, chat_id: int, code: string}>  $expected
     * @param  list<array{chat_id: int, text: string, ts: float}>  $stubLog
     */
    private function countOtherReplies(array $expected, array $stubLog): int
    {
        $knownChatIds = array_flip(array_column($expected, 'chat_id'));
        $count        = 0;

        foreach ($stubLog as $entry) {
            if (! isset($knownChatIds[$entry['chat_id']])) {
                continue;
            }

            if (LoadTestFlowBlueprint::PROMPT_TEXT === $entry['text']) {
                continue;
            }

            if (str_starts_with($entry['text'], LoadTestFlowBlueprint::CONFIRM_PREFIX)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Scoped to jobs that failed during this run rather than the whole
     * table: `failed_jobs` is shared with everything else that ever ran
     * against this database, and a load test has no business failing over
     * cruft some earlier, unrelated piece of work left behind.
     *
     * @param  array<string, mixed>  $state
     */
    private function countFailedJobsSince(array $state): int
    {
        $createdAt = $state['created_at'] ?? null;

        $query = DB::table('failed_jobs');

        if (is_string($createdAt) && '' !== $createdAt) {
            $query->where('failed_at', '>=', CarbonImmutable::parse($createdAt));
        }

        return (int)$query->count();
    }

    /**
     * @param  list<array<string, mixed>>  $stubLeaks
     * @param  list<array<string, mixed>>  $tenantBreaches
     * @param  array<string, int>  $sessionStatusCounts
     */
    private function printReport(
        LoadTestVerificationReport $report,
        array $stubLeaks,
        int $otherReplies,
        array $tenantBreaches,
        array $sessionStatusCounts,
        int $failedJobs,
        int $rateLimit,
        float $maxDroppedRatio,
    ): void {
        $this->components->info('Load test verification');
        $this->components->twoColumnDetail('Total contacts', (string)$report->total);
        $this->components->twoColumnDetail('OK', (string)$report->ok);
        $this->components->twoColumnDetail(
            'Dropped (no code captured)',
            sprintf('%d (%.1f%%, threshold %.1f%%)', $report->dropped, $report->droppedRatio() * 100, $maxDroppedRatio * 100),
        );
        $this->components->twoColumnDetail('Leaked codes (contact attribute)', (string)count($report->leaks));
        $this->components->twoColumnDetail('Leaked codes (stub transcript)', (string)count($stubLeaks));
        $this->components->twoColumnDetail('Corrupted values (unknown)', (string)count($report->corrupted));
        $this->components->twoColumnDetail('Cross-tenant rows found', (string)count($tenantBreaches));
        $this->components->twoColumnDetail('failed_jobs', (string)$failedJobs);
        $this->components->twoColumnDetail('Other/busy stub replies', (string)$otherReplies);
        $this->components->twoColumnDetail(
            'Session status',
            [] !== $sessionStatusCounts
                ? implode(', ', array_map(static fn (string $status, int $count): string => "{$status}={$count}", array_keys($sessionStatusCounts), $sessionStatusCounts))
                : '(none)',
        );
        $this->components->twoColumnDetail('MessageSender rate limit', "{$rateLimit}/min per channel+chat (config messaging.rate_limit_per_minute)");

        if ($report->hasLeaks()) {
            $this->table(
                ['chat_id', 'tenant', 'expected_code', 'actual_code', 'leaked_from_tenant', 'leaked_from_chat_id'],
                $report->leaks,
            );
        }

        if ([] !== $stubLeaks) {
            $this->table(
                ['chat_id', 'contains_code', 'code_owner_tenant', 'code_owner_chat_id'],
                $stubLeaks,
            );
        }

        if ($report->hasCorruption()) {
            $this->table(['chat_id', 'tenant_slug', 'expected_code', 'actual_code'], $report->corrupted);
        }

        if ([] !== $tenantBreaches) {
            $this->table(['kind', 'schema', 'row_tenant_id', 'expected_tenant_id'], $tenantBreaches);
        }
    }
}
