<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * For a Filament create page whose resource has a record limit: the page is closed (403) when it
 * is opened at the limit, but not again on later requests of the same page.
 *
 * Filament re-runs `authorizeAccess()` on every request (`hydrate()`, `create()`). A page opened
 * below the limit and saved after another request took the last slot must still reach the
 * service, which refuses with {@see \Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException}
 * and lets the page show a notification, so the limit is part of the check only on mount.
 * The policy is checked every time.
 *
 * The resource provides `canCreateIgnoringLimit()` and `isLimitReached()`.
 */
trait ChecksRecordLimitOnMount
{
    protected bool $mounting = false;

    public function mount(): void
    {
        $this->mounting = true;

        try {
            parent::mount();
        } finally {
            $this->mounting = false;
        }
    }

    protected function authorizeAccess(): void
    {
        $resource = static::getResource();

        abort_unless($resource::canCreateIgnoringLimit(), 403);
        abort_if($this->mounting && $resource::isLimitReached(), 403);
    }
}
