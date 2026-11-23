<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

/**
 * What became of one call to an API trigger; the controller turns each into its HTTP answer.
 */
enum ApiTriggerOutcomeStatus: string
{
    /** A run was queued (202). */
    case Accepted = 'accepted';

    /** A `sync` trigger's flow answered while the call waited, or a repeat found that answer kept: the flow's answer. */
    case Replied = 'replied';

    /** The `Idempotency-Key` was seen for this trigger and token within the dedup window; nothing was queued (200). */
    case Duplicate = 'duplicate';

    /** No live API trigger behind the id, the same answer for every reason (404). */
    case NotFound = 'not_found';

    /** The body is not declared `application/json` (415). */
    case UnsupportedMediaType = 'unsupported_media_type';

    /** The body is over {@see FlowApiTriggerReceiver::MAX_BODY_BYTES} (413). */
    case PayloadTooLarge = 'payload_too_large';

    /** The body is not a JSON object, or the `Idempotency-Key` is not 1–255 printable ASCII characters (422). */
    case InvalidPayload = 'invalid_payload';

    /** The run rate breaker paused the trigger; the caller should retry later (503). */
    case TriggerPaused = 'trigger_paused';
}
