<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

use App\Domains\Staff\Models\SupportAccessEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * The `support_access` record of a session opened through a support entry.
 *
 * Its presence is what makes a session a support session: it names the operator for the banner,
 * points at the entry to close, and carries the moment the session ends.
 */
final readonly class SupportAccessSession
{
    public const string KEY = 'support_access';

    /** Minutes a support session lasts, whatever the activity. */
    public const int LIFETIME_MINUTES = 60;

    public function __construct(
        private Session $session,
    ) {
    }

    public function start(SupportAccessEntry $entry): void
    {
        $this->session->put(self::KEY, [
            'entry_id'       => $entry->getKey(),
            'operator_name'  => $entry->operator_name,
            'operator_email' => $entry->operator_email,
            'expires_at'     => CarbonImmutable::now()->addMinutes(self::LIFETIME_MINUTES)->toIso8601String(),
        ]);
    }

    /**
     * @return array{entry_id: string, operator_name: string, operator_email: string, expires_at: string}|null
     */
    public function current(): ?array
    {
        $value = $this->session->get(self::KEY);

        if (! is_array($value) || ! isset($value['entry_id'], $value['operator_name'], $value['operator_email'], $value['expires_at'])) {
            return null;
        }

        return [
            'entry_id'       => (string) $value['entry_id'],
            'operator_name'  => (string) $value['operator_name'],
            'operator_email' => (string) $value['operator_email'],
            'expires_at'     => (string) $value['expires_at'],
        ];
    }

    public function isExpired(): bool
    {
        $current = $this->current();

        return null !== $current && CarbonImmutable::parse($current['expires_at'])->isPast();
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
