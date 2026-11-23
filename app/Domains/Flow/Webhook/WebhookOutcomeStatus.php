<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * What became of one request to a webhook trigger; the controller turns each into its HTTP answer.
 */
enum WebhookOutcomeStatus: string
{
    /** A run was queued (202). */
    case Accepted = 'accepted';

    /** A `sync` trigger's flow answered while the request waited, or a repeat found that answer kept: the flow's answer. */
    case Replied = 'replied';

    /** The delivery id was seen within the dedup window; nothing was queued (200). */
    case Duplicate = 'duplicate';

    /** No live webhook trigger behind the token, the same answer for every reason (404). */
    case NotFound = 'not_found';

    /** The signature, the secret header or the timestamp did not check out (401). */
    case InvalidSignature = 'invalid_signature';

    /** Too many requests for the token, or too many unknown tokens from the address (429). */
    case Throttled = 'throttled';

    /** The body is over {@see FlowWebhookReceiver::MAX_BODY_BYTES} (413). */
    case PayloadTooLarge = 'payload_too_large';

    /** A multipart body, which PHP keeps from the raw input the check and the flow read (415). */
    case UnsupportedMediaType = 'unsupported_media_type';

    /** The tenant is stopped; the sender should retry later (503). */
    case TenantStopped = 'tenant_stopped';

    /** The run rate breaker paused the trigger; the sender should retry later (503). */
    case TriggerPaused = 'trigger_paused';
}
