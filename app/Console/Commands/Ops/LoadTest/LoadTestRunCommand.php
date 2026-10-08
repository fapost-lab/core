<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestFlowBlueprint;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestLatencyMatcher;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestStateStore;
use App\Console\Commands\Ops\LoadTest\Support\RefusesProduction;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Drives synthetic Telegram webhook traffic against every contact
 * `loadtest:seed` planned.
 *
 * Each contact sends its messages back to back, message 2 always being its
 * own code — the one the `input` node captures. By default a contact's own
 * messages are sent one at a time, waiting for the webhook POST to be
 * accepted before sending the next: that puts the contention where the
 * design wants it (many contacts hitting the pipeline together) without
 * also racing a contact against itself. `--burst` removes that wait, adding
 * same-session lock contention on top.
 */
final class LoadTestRunCommand extends Command
{
    use RefusesProduction;

    /** @var list<string> */
    private const array DRAINED_QUEUES = ['flow.execution', 'messaging.transactional', 'messaging.logging'];

    /** @var string */
    protected $signature = 'loadtest:run
        {--url= : Base URL of the running app (default: app.url)}
        {--messages=3 : Messages per contact; must be >=2, the 2nd message is always the code}
        {--concurrency=50 : Max requests per HTTP pool batch}
        {--burst : Send all messages for each contact together, instead of waiting between them}
        {--timeout=120 : Seconds to wait for flow.execution/messaging.transactional/messaging.logging to drain}';

    /** @var string */
    protected $description = 'Send synthetic Telegram webhook traffic for the seeded load-test contacts';

    public function __construct(
        private readonly LoadTestStateStore $state,
        private readonly LoadTestLatencyMatcher $latencyMatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->refuseInProduction()) {
            return self::FAILURE;
        }

        $state = $this->state->load();

        $baseUrl     = mb_rtrim((string)($this->option('url') ?: config('app.url')), '/');
        $messages    = max(2, (int)$this->option('messages'));
        $concurrency = max(1, (int)$this->option('concurrency'));
        $burst       = (bool)$this->option('burst');
        $timeout     = max(1, (int)$this->option('timeout'));

        $tenantsBySlug = [];
        foreach ($state['tenants'] ?? [] as $tenant) {
            $tenantsBySlug[$tenant['slug']] = $tenant;
        }

        /** @var list<array<string, mixed>> $contacts */
        $contacts = $state['contacts'] ?? [];

        if ([] === $contacts) {
            $this->components->warn('No contacts in state — nothing to send. Run loadtest:seed first.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d contact(s), %d message(s) each, %s, base URL %s',
            count($contacts),
            $messages,
            $burst ? 'burst' : 'sequential per contact',
            $baseUrl,
        ));

        $updateId = (int)(microtime(true) * 1000);
        $sentAt   = [];
        $sent     = 0;
        $failed   = 0;
        $started  = microtime(true);

        if ($burst) {
            $requests = [];
            foreach ($contacts as $contact) {
                $tenant = $tenantsBySlug[$contact['tenant_slug']] ?? null;
                if (null === $tenant) {
                    continue;
                }

                for ($round = 1; $round <= $messages; $round++) {
                    $requests["{$contact['chat_id']}:{$round}"] = $this->buildRequest($baseUrl, $tenant, $contact, $round, $updateId++);

                    if (2 === $round) {
                        $sentAt[$contact['chat_id']] = microtime(true);
                    }
                }
            }

            [$roundSent, $roundFailed] = $this->sendPool($requests, $concurrency);
            $sent += $roundSent;
            $failed += $roundFailed;
        } else {
            for ($round = 1; $round <= $messages; $round++) {
                $requests = [];

                foreach ($contacts as $contact) {
                    $tenant = $tenantsBySlug[$contact['tenant_slug']] ?? null;
                    if (null === $tenant) {
                        continue;
                    }

                    $requests[(string)$contact['chat_id']] = $this->buildRequest($baseUrl, $tenant, $contact, $round, $updateId++);
                }

                if (2 === $round) {
                    $now = microtime(true);
                    foreach ($requests as $chatId => $request) {
                        $sentAt[(int)$chatId] = $now;
                    }
                }

                [$roundSent, $roundFailed] = $this->sendPool($requests, $concurrency);
                $sent += $roundSent;
                $failed += $roundFailed;
            }
        }

        $duration = microtime(true) - $started;

        $this->components->twoColumnDetail('Requests sent', (string)$sent);
        $this->components->twoColumnDetail('HTTP failures', (string)$failed);
        $this->components->twoColumnDetail('Throughput', sprintf('%.1f req/s', $duration > 0 ? $sent / $duration : 0));

        $drainedWithinTimeout = $this->waitForQueuesToDrain($timeout);
        $this->components->twoColumnDetail('Queues drained', $drainedWithinTimeout ? 'yes' : "no (timeout after {$timeout}s)");

        $this->reportLatency($state, $sentAt);

        return $drainedWithinTimeout ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $tenant
     * @param  array<string, mixed>  $contact
     *
     * @return array{url: string, headers: array<string, string>, body: array<string, mixed>}
     */
    private function buildRequest(string $baseUrl, array $tenant, array $contact, int $round, int $updateId): array
    {
        $text = match (true) {
            1 === $round => 'hello',
            2 === $round => (string)$contact['code'],
            default      => 'ping',
        };

        $chatId = (int)$contact['chat_id'];

        return [
            'url'     => "{$baseUrl}/webhook/telegram/{$tenant['channel_hash']}",
            'headers' => [
                'X-Telegram-Bot-Api-Secret-Token' => (string)$tenant['secret_token'],
            ],
            'body' => [
                'update_id' => $updateId,
                'message'   => [
                    'message_id' => $round,
                    'date'       => time(),
                    'chat'       => ['id' => $chatId],
                    'from'       => ['id' => $chatId, 'first_name' => 'LoadTest'],
                    'text'       => $text,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array{url: string, headers: array<string, string>, body: array<string, mixed>}>  $requests
     *
     * @return array{0: int, 1: int}  [sent, failed]
     */
    private function sendPool(array $requests, int $concurrency): array
    {
        if ([] === $requests) {
            return [0, 0];
        }

        $sent   = 0;
        $failed = 0;

        foreach (array_chunk($requests, $concurrency, preserve_keys: true) as $chunk) {
            $responses = Http::pool(static function (Pool $pool) use ($chunk): array {
                $calls = [];

                foreach ($chunk as $key => $request) {
                    // Array keys built from an all-digit chat id are silently cast to int
                    // by PHP — Pool::as() requires a string, so it is forced back here.
                    $calls[$key] = $pool->as((string)$key)
                        ->withHeaders($request['headers'])
                        ->timeout(10)
                        ->post($request['url'], $request['body']);
                }

                return $calls;
            });

            foreach ($responses as $response) {
                $sent++;

                if ($response instanceof Throwable || ! $response->successful()) {
                    $failed++;
                }
            }
        }

        return [$sent, $failed];
    }

    private function waitForQueuesToDrain(int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $sizes = [];
            foreach (self::DRAINED_QUEUES as $queue) {
                $sizes[$queue] = Queue::size($queue);
            }

            if (0 === array_sum($sizes)) {
                return true;
            }

            sleep(1);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, float>  $sentAt
     */
    private function reportLatency(array $state, array $sentAt): void
    {
        if ([] === $sentAt) {
            return;
        }

        $logPath = (string)($state['stub_log_path'] ?? storage_path('logs/loadtest-stub.jsonl'));

        if (! is_file($logPath)) {
            $this->components->warn("Stub log not found at {$logPath} — skipping latency report.");

            return;
        }

        $entries = [];
        foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['chat_id'], $decoded['text'], $decoded['ts'])) {
                $entries[] = [
                    'chat_id' => (int)$decoded['chat_id'],
                    'text'    => (string)$decoded['text'],
                    'ts'      => (float)$decoded['ts'],
                ];
            }
        }

        $latencies = $this->latencyMatcher->match($sentAt, $entries, LoadTestFlowBlueprint::CONFIRM_PREFIX);

        if ([] === $latencies) {
            $this->components->warn('No confirm replies matched in the stub log yet — latency unavailable.');

            return;
        }

        $p50 = $this->latencyMatcher->percentile($latencies, 50);
        $p95 = $this->latencyMatcher->percentile($latencies, 95);

        $this->components->twoColumnDetail('Latency p50', null !== $p50 ? sprintf('%.3fs', $p50) : 'n/a');
        $this->components->twoColumnDetail('Latency p95', null !== $p95 ? sprintf('%.3fs', $p95) : 'n/a');
        $this->components->twoColumnDetail('Confirmed', sprintf('%d/%d', count($latencies), count($sentAt)));
    }
}
