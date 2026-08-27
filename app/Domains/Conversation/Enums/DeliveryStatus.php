<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Delivery state of a logged message. Inbound rows are always {@see self::Received};
 * outbound rows progress through queued → sent → delivered → read (or failed).
 * Provider support varies: Telegram gives no read receipts, so outbound stays at
 * {@see self::Sent}; WhatsApp reports delivered/read via status webhooks (§7.5).
 */
enum DeliveryStatus: string
{
    case Received  = 'received';
    case Queued    = 'queued';
    case Sent      = 'sent';
    case Delivered = 'delivered';
    case Read      = 'read';
    case Failed    = 'failed';
}
