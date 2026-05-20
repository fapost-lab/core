<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use FAPost\Foundation\Flow\Enums\StateNamespace;

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
}
