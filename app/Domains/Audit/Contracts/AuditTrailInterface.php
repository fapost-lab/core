<?php

declare(strict_types=1);

namespace App\Domains\Audit\Contracts;

use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Exceptions\AuditEntryNotRecordedException;

/**
 * Records one action of a person in the current tenant's audit log.
 *
 * MAY:      be called from the write paths of the console and the builder (their domain services) and from the
 *           sign-in listeners; take an explicit actor from the caller.
 * MUST:     name an action of the catalogue ({@see \App\Domains\Audit\Enums\AuditAction}); pass only the fields the
 *           action whitelists; be called inside the transaction of the change when there is one, so a rolled-back
 *           change leaves no entry and an entry that fails fails the change.
 * MUST NOT: be called from the flow runtime, Messaging, webhook ingress or broadcast jobs; receive a token, a hash,
 *           a channel's config or a password, by value or by key; queue the entry, buffer it or use `activity()`.
 * Why:      the log is a control over what people did; on the hot path it costs money and records noise, and a
 *           secret in it lives a year and reaches the browser.
 */
interface AuditTrailInterface
{
    /**
     * @throws AuditEntryNotRecordedException when the entry could not be written
     */
    public function record(AuditRecord $record): void;
}
