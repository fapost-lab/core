<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use Fapost\Foundation\Flow\Enums\StateNamespace;

final class RagStateKeys
{
    public const string FOUND      = StateNamespace::Rag->value . '.found';
    public const string CONFIDENCE = StateNamespace::Rag->value . '.confidence';
    public const string ANSWER     = StateNamespace::Rag->value . '.answer';
    public const string INTENT     = StateNamespace::Rag->value . '.intent';
}
