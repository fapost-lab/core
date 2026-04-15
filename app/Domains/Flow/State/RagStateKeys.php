<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

final class RagStateKeys
{
    public const string FOUND      = FlowStateNamespace::RAG . '.found';
    public const string CONFIDENCE = FlowStateNamespace::RAG . '.confidence';
    public const string ANSWER     = FlowStateNamespace::RAG . '.answer';
    public const string INTENT     = FlowStateNamespace::RAG . '.intent';
}
