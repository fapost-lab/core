<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use RuntimeException;

/**
 * The builder asked for the endpoint of a trigger the flow's draft does not list as a webhook trigger, or of one that
 * belongs to another flow. Answered as "not found", so it says nothing about other flows.
 */
final class WebhookEndpointUnavailableException extends RuntimeException
{
}
