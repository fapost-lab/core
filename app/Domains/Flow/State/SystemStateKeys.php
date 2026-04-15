<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

final class SystemStateKeys
{
    public const string STARTED_AT_LEAF   = 'started_at';
    public const string RETRY_COUNT_LEAF  = 'retry_count';
    public const string SENT_MESSAGES     = FlowStateNamespace::SYSTEM . '.sent_messages';
    public const string STARTED_AT        = FlowStateNamespace::SYSTEM . '.started_at';
    public const string RETRY_COUNT       = FlowStateNamespace::SYSTEM . '.retry_count';
    public const string DELAY_NODE_PREFIX = FlowStateNamespace::SYSTEM . '.delay';
}
