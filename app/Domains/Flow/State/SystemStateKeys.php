<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use Fapost\Foundation\Flow\Enums\StateNamespace;

final class SystemStateKeys
{
    public const string STARTED_AT_LEAF              = 'started_at';
    public const string RETRY_COUNT_LEAF             = 'retry_count';
    public const string SENT_MESSAGES                = StateNamespace::System->value . '.sent_messages';
    public const string STARTED_AT                   = StateNamespace::System->value . '.started_at';
    public const string RETRY_COUNT                  = StateNamespace::System->value . '.retry_count';
    public const string DELAY_NODE_PREFIX            = StateNamespace::System->value . '.delay';
    public const string LANGUAGE                     = StateNamespace::System->value . '.language';
    public const string SEND_MESSAGE_TIMEOUT_PREFIX  = StateNamespace::System->value . '.send_message.timeout';
    public const string SEND_MESSAGE_RESPONSE_PREFIX = StateNamespace::System->value . '.send_message.response';
    public const string SEND_MESSAGE_DYNAMIC_BUTTONS = StateNamespace::System->value . '.send_message.dynamic_buttons';

    /**
     * Prefix for the per-node `notify_staff` dispatch marker. Full path:
     * `system.staff_notified.{nodeId}`. Set once the delivery job is queued so a
     * re-execution of the node never dispatches a duplicate notification.
     */
    public const string STAFF_NOTIFIED_PREFIX = StateNamespace::System->value . '.staff_notified';

    /**
     * Prefix for the per-node `notify` contacts-mode dispatch marker. Full path:
     * `system.contacts_notified.{nodeId}`. Set once the fan-out job is queued so a
     * re-execution of the node never enqueues a duplicate broadcast.
     */
    public const string CONTACTS_NOTIFIED_PREFIX = StateNamespace::System->value . '.contacts_notified';

    /**
     * Prefix for the per-node `set_tag` execution marker. Full path:
     * `system.set_tag.{nodeId}`. Set after the tag mutations are applied so a
     * re-execution of the node (queue retry under the session lock) never
     * re-applies non-idempotent actions such as `toggle`.
     */
    public const string SET_TAG_PREFIX = StateNamespace::System->value . '.set_tag';

    /**
     * Prefix for per-input-node retry counters. Full path:
     * `system.input.{nodeId}.retry_count`. Incremented whenever validation
     * fails; cleared when the node finally emits success or `invalid`.
     */
    public const string INPUT_RETRY_PREFIX = StateNamespace::System->value . '.input';
}
