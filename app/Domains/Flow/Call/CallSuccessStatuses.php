<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use Fapost\Foundation\Flow\Call\CallResult;

/**
 * The `success_statuses` option of the `call` node: the response statuses that count as success.
 *
 * Generic: it applies to every transport whose result carries `metadata.status_code` (today `http`). It replaces the
 * transport's own verdict (the HTTP transport's `success_when`) in both directions: a listed status is a success even
 * when the transport called it an error, and an unlisted one is an error even when it was a 2xx. A result with no
 * status (a transport failure, an in-process action) keeps the transport's verdict. Without the option nothing changes.
 *
 * Entries are an integer or a string: `404`, `"404"`, `"2xx"` (a class) or `"200-299"` (a range), 100 to 599.
 */
final readonly class CallSuccessStatuses
{
    public const int MAX_ENTRIES = 20;

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function __construct(private array $ranges)
    {
    }

    /**
     * The statuses the config lists, or null when it lists none (the option is absent, empty or malformed).
     * A malformed list is a publish error ({@see self::problem()}), so it never reaches a running flow.
     */
    public static function fromConfig(mixed $raw): ?self
    {
        if (null !== self::problem($raw) || ! is_array($raw) || [] === $raw) {
            return null;
        }

        $ranges = [];

        foreach ($raw as $entry) {
            $ranges[] = self::range($entry);
        }

        /** @var list<array{0: int, 1: int}> $ranges */
        return new self($ranges);
    }

    /**
     * What is wrong with a `success_statuses` value, or null when it is acceptable (absent included).
     */
    public static function problem(mixed $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return 'success_statuses must be a list of statuses, such as [200, "3xx", "400-404"].';
        }

        if (count($raw) > self::MAX_ENTRIES) {
            return 'success_statuses lists at most ' . self::MAX_ENTRIES . ' entries.';
        }

        foreach ($raw as $entry) {
            if (null === self::range($entry)) {
                $shown = is_scalar($entry) ? (string) $entry : get_debug_type($entry);

                return "success_statuses entry '{$shown}' is not a status. Use a number from 100 to 599, a class such as \"2xx\", or a range such as \"200-299\".";
            }
        }

        return null;
    }

    /**
     * The `error_code` of a response the policy did not accept.
     */
    public static function errorCodeFor(int $status): string
    {
        return $status >= 500 ? 'http_5xx' : ($status >= 400 ? 'http_4xx' : 'http_other');
    }

    public function accepts(int $status): bool
    {
        foreach ($this->ranges as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The result the node acts on: the transport's verdict replaced by this list when the result carries a status.
     */
    public function judge(CallResult $result): CallResult
    {
        $status = $result->metadata['status_code'] ?? null;

        if (! is_int($status)) {
            return $result;
        }

        $accepted = $this->accepts($status);

        if ($accepted === $result->success) {
            return $result;
        }

        return $accepted
            ? CallResult::ok($result->payload, $result->metadata)
            : CallResult::error(self::errorCodeFor($status), $result->payload, $result->metadata);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function range(mixed $entry): ?array
    {
        if (is_int($entry)) {
            return self::valid($entry, $entry);
        }

        if (! is_string($entry)) {
            return null;
        }

        $entry = mb_strtolower(mb_trim($entry));

        if (1 === preg_match('/^(\d{3})$/', $entry, $m)) {
            return self::valid((int) $m[1], (int) $m[1]);
        }

        if (1 === preg_match('/^([1-5])xx$/', $entry, $m)) {
            return [(int) $m[1] * 100, (int) $m[1] * 100 + 99];
        }

        if (1 === preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $entry, $m)) {
            return self::valid((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function valid(int $from, int $to): ?array
    {
        return $from >= 100 && $to <= 599 && $from <= $to ? [$from, $to] : null;
    }
}
