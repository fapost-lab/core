<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Transports\HttpTransport;
use Fapost\Foundation\Flow\Call\CallResult;

/**
 * The `retry` option of the `call` node: how many times a failed call is sent, how long it waits in between, and
 * which failures are worth another send.
 *
 * Only the node's verdict is retried ({@see CallSuccessStatuses::judge()} runs first), so a status the node accepts
 * is never sent again. Retryable failures are `network` (`transport_failure`: no connection, a timeout), `5xx` and
 * `429` (by `metadata.status_code`). Everything else (another 4xx, a refused egress, an invalid target, a Solution
 * refusal) is answered the same way on every send and goes to `error` at once.
 *
 * The waits happen inside the step, so every retry has to fit the node's window ({@see self::WINDOW_SECONDS}) counted
 * from the first send: the session lock is extended only between nodes, and the segment budget of an automation run
 * assumes no node runs longer than that (`adr-20261119-call-retry-inside-the-node-window`). A retry is sent only when its
 * delay, the longest name lookup ({@see self::LOOKUP_ALLOWANCE_SECONDS}) and at least one second of request still fit,
 * and its timeout is cut to what is left.
 *
 * Every field is a literal, never a template; a malformed value is a publish error ({@see self::problem()}).
 */
final readonly class CallRetryPolicy
{
    public const int MAX_ATTEMPTS = 5;

    public const int DEFAULT_ATTEMPTS = 3;

    public const int MIN_DELAY_MS = 100;

    public const int MAX_DELAY_MS = 5000;

    public const int DEFAULT_DELAY_MS = 500;

    /**
     * The longest a retrying node runs, from its first send: the egress lookup cap plus the default request timeout,
     * the "slowest node" the segment budget of an automation run is sized against.
     */
    public const int WINDOW_SECONDS = EgressGuardMiddleware::LOOKUP_DEADLINE_SECONDS + HttpTransport::DEFAULT_TIMEOUT_SECONDS;

    /**
     * Kept free for each retry's name lookup, which the request timeout does not cover.
     */
    public const int LOOKUP_ALLOWANCE_SECONDS = EgressGuardMiddleware::LOOKUP_DEADLINE_SECONDS;

    public const int MIN_REQUEST_SECONDS = 1;

    public const string ON_NETWORK = 'network';

    public const string ON_5XX = '5xx';

    public const string ON_429 = '429';

    public const string BACKOFF_FIXED = 'fixed';

    public const string BACKOFF_EXPONENTIAL = 'exponential';

    private const array ON_ALL = [self::ON_NETWORK, self::ON_5XX, self::ON_429];

    private const array FIELDS = ['max_attempts', 'delay_ms', 'backoff', 'on'];

    /**
     * @param  list<string>  $on
     */
    private function __construct(
        public int $maxAttempts,
        private int $delayMs,
        private bool $exponential,
        private array $on,
    ) {
    }

    /**
     * The policy the config describes, or null when the node does not retry (no `retry`, one attempt, or malformed).
     */
    public static function fromConfig(mixed $raw): ?self
    {
        if (null === $raw || null !== self::problem($raw) || ! is_array($raw)) {
            return null;
        }

        $attempts = is_int($raw['max_attempts'] ?? null) ? $raw['max_attempts'] : self::DEFAULT_ATTEMPTS;

        if ($attempts < 2) {
            return null;
        }

        $on = is_array($raw['on'] ?? null) ? array_values(array_unique($raw['on'])) : self::ON_ALL;

        /** @var list<string> $on */
        return new self(
            maxAttempts: $attempts,
            delayMs: is_int($raw['delay_ms'] ?? null) ? $raw['delay_ms'] : self::DEFAULT_DELAY_MS,
            exponential: self::BACKOFF_FIXED !== ($raw['backoff'] ?? self::BACKOFF_EXPONENTIAL),
            on: $on,
        );
    }

    /**
     * What is wrong with a `retry` value, or null when it is acceptable (absent included).
     */
    public static function problem(mixed $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        if (! is_array($raw) || ([] !== $raw && array_is_list($raw))) {
            return 'retry must be an object such as {"max_attempts": 3, "delay_ms": 500}.';
        }

        $unknown = array_diff(array_map(strval(...), array_keys($raw)), self::FIELDS);

        if ([] !== $unknown) {
            return 'retry has unknown fields: ' . implode(', ', $unknown) . '. Use ' . implode(', ', self::FIELDS) . '.';
        }

        $attempts = $raw['max_attempts'] ?? null;

        if (null !== $attempts && (! is_int($attempts) || $attempts < 1 || $attempts > self::MAX_ATTEMPTS)) {
            return 'retry.max_attempts must be a whole number from 1 to ' . self::MAX_ATTEMPTS . ' (the first send included).';
        }

        $delay = $raw['delay_ms'] ?? null;

        if (null !== $delay && (! is_int($delay) || $delay < self::MIN_DELAY_MS || $delay > self::MAX_DELAY_MS)) {
            return 'retry.delay_ms must be a whole number of milliseconds from ' . self::MIN_DELAY_MS . ' to ' . self::MAX_DELAY_MS . '.';
        }

        $backoff = $raw['backoff'] ?? null;

        if (null !== $backoff && ! in_array($backoff, [self::BACKOFF_FIXED, self::BACKOFF_EXPONENTIAL], true)) {
            return 'retry.backoff must be "fixed" or "exponential".';
        }

        $on = $raw['on'] ?? null;

        if (null !== $on && (! is_array($on) || [] === $on || ! array_is_list($on) || ! self::onlyKnownTriggers($on))) {
            return 'retry.on must list one or more of "network", "5xx" and "429".';
        }

        return null;
    }

    /**
     * The request timeout, in whole seconds, a send after `$elapsedMs` and a wait of `$delayMs` can still have inside
     * the window; null when it does not fit.
     */
    public static function secondsLeft(float $elapsedMs, int $delayMs): ?int
    {
        $left = (int) floor((self::WINDOW_SECONDS * 1000 - $elapsedMs - $delayMs) / 1000) - self::LOOKUP_ALLOWANCE_SECONDS;

        return $left >= self::MIN_REQUEST_SECONDS ? $left : null;
    }

    /**
     * Whether another send could turn this failure into a success.
     */
    public function retries(CallResult $result): bool
    {
        if ($result->success) {
            return false;
        }

        $status = $result->metadata['status_code'] ?? null;

        if (is_int($status)) {
            return (429 === $status && in_array(self::ON_429, $this->on, true))
                || ($status >= 500 && $status <= 599 && in_array(self::ON_5XX, $this->on, true));
        }

        return 'transport_failure' === $result->errorCode && in_array(self::ON_NETWORK, $this->on, true);
    }

    /**
     * How long to wait before the next send, after `$sends` sends: the backoff, or the response's `Retry-After`
     * (in seconds) when it asks for longer.
     */
    public function delayMs(int $sends, CallResult $result): int
    {
        $delay = $this->exponential
            ? min(self::MAX_DELAY_MS, $this->delayMs * 2 ** max(0, $sends - 1))
            : $this->delayMs;

        $retryAfter = self::retryAfterSeconds($result);

        return null === $retryAfter ? $delay : max($delay, $retryAfter * 1000);
    }

    /**
     * Strings only: `429` as a number would pass a loose comparison here and then never match in {@see self::retries()}.
     *
     * @param  list<mixed>  $on
     */
    private static function onlyKnownTriggers(array $on): bool
    {
        foreach ($on as $trigger) {
            if (! in_array($trigger, self::ON_ALL, true)) {
                return false;
            }
        }

        return true;
    }

    private static function retryAfterSeconds(CallResult $result): ?int
    {
        $headers = $result->metadata['headers'] ?? null;

        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && 0 === strcasecmp($name, 'Retry-After') && is_string($value) && ctype_digit(mb_trim($value))) {
                // Anything past the window means no retry anyway; the cap only keeps the number small.
                return min((int) mb_trim($value), 3600);
            }
        }

        return null;
    }
}
