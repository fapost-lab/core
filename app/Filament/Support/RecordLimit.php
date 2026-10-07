<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Notifications\Notification;

/**
 * Filament side of a record limit, read once: whether the tenant has no room for one more record,
 * the "N of M" hint, and the notification for a create that raced past the page check.
 *
 * It holds the limit and the count as they were when it was built, so a page builds one per
 * render and asks it as often as it likes without repeating the count query. There is no static
 * state: a new request builds a new one.
 *
 * `$translationPrefix` names a translation group holding `hint` and `reached_title`, such as
 * `staff.assistants.limit`.
 */
final readonly class RecordLimit
{
    public bool $reached;

    public ?int $limit;

    public function __construct(
        string $key,
        public int $current,
        private string $translationPrefix,
    ) {
        $this->limit   = app(RecordQuotaInterface::class)->limit($key);
        $this->reached = null !== $this->limit && $current >= $this->limit;
    }

    public static function notifyReached(RecordLimitReachedException $exception, string $translationPrefix): void
    {
        Notification::make()
            ->danger()
            ->title(__($translationPrefix . '.reached_title'))
            ->body($exception->getMessage())
            ->send();
    }

    /**
     * Human-readable "N of M", or null when the tenant has no limit.
     */
    public function hint(): ?string
    {
        if (null === $this->limit) {
            return null;
        }

        return __($this->translationPrefix . '.hint', ['current' => $this->current, 'limit' => $this->limit]);
    }

    /**
     * The hint, but only while the limit is reached (a page subheading).
     */
    public function hintWhenReached(): ?string
    {
        return $this->reached ? $this->hint() : null;
    }
}
