<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * Matches each contact's "code" webhook POST to the confirm message the stub
 * logged for that same chat, and reduces the resulting round-trip times to
 * the percentiles `loadtest:run` prints.
 *
 * Pure: takes plain timestamps/log rows, returns plain numbers, so the
 * matching rule (earliest stub entry at-or-after the send, same chat) and
 * the percentile math are both unit testable without a stub process or an
 * HTTP client.
 */
final class LoadTestLatencyMatcher
{
    /**
     * @param  array<int, float>  $sentAtByChatId  chat_id => unix timestamp (seconds, float) the code was POSTed at
     * @param  list<array{chat_id: int, text: string, ts: float}>  $stubEntries  outbound stub log rows
     *
     * @return list<float>  round-trip seconds, one per contact that got a confirm reply
     */
    public function match(array $sentAtByChatId, array $stubEntries, string $confirmPrefix): array
    {
        $byChatId = [];
        foreach ($stubEntries as $entry) {
            if (! str_starts_with($entry['text'], $confirmPrefix)) {
                continue;
            }

            $byChatId[$entry['chat_id']][] = $entry;
        }

        $latencies = [];

        foreach ($sentAtByChatId as $chatId => $sentAt) {
            $candidates = $byChatId[$chatId] ?? [];

            $earliestAfterSend = null;
            foreach ($candidates as $candidate) {
                if ($candidate['ts'] < $sentAt) {
                    continue;
                }

                if (null === $earliestAfterSend || $candidate['ts'] < $earliestAfterSend) {
                    $earliestAfterSend = $candidate['ts'];
                }
            }

            if (null !== $earliestAfterSend) {
                $latencies[] = $earliestAfterSend - $sentAt;
            }
        }

        return $latencies;
    }

    /**
     * Nearest-rank percentile over a list of seconds. Returns null for an
     * empty sample rather than dividing by zero.
     *
     * @param  list<float>  $values
     */
    public function percentile(array $values, float $percentile): ?float
    {
        if ([] === $values) {
            return null;
        }

        sort($values);

        $index = (int)ceil($percentile / 100 * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return $values[$index];
    }
}
