<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

/**
 * What a limit turned away, in the words the tenant's admins are told. Derived at the point of refusal.
 */
enum RefusedWork: string
{
    case RecordCreation  = 'record_creation';
    case InboundMessage  = 'inbound_message';
    case OutboundMessage = 'outbound_message';
    case CallExecution   = 'call_execution';
    case MediaUpload     = 'media_upload';
    case InboundMedia    = 'inbound_media';
    case Other           = 'other';
}
