<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * The ceiling on webhook requests held open for a flow's answer: at most so many on the installation and so many per
 * tenant at a time. A request waits only while it holds a slot; without one it is answered 202 at once, so waiting
 * senders can never take the whole PHP-FPM pool.
 */
interface WebhookWaitSlotsInterface
{
    /**
     * Takes a slot on both levels, or none. A slot lapses on its own after `$holdSeconds`, so one a crashed request
     * never gave back does not stay taken.
     *
     * @return string|null the slot to give back, or null when either level is full or the store cannot answer
     */
    public function acquire(string $tenantId, int $installationMax, int $tenantMax, int $holdSeconds): ?string;

    public function release(string $tenantId, string $slot): void;
}
