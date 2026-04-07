<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

enum StateNamespace: string
{
    case System = 'system';
    case Flow   = 'flow';
    case Rag    = 'rag';
    case Module = 'module';

    public function writePolicy(): NamespaceWritePolicy
    {
        return match ($this) {
            self::System => NamespaceWritePolicy::EngineOnly,
            self::Flow   => NamespaceWritePolicy::Writable,
            self::Rag    => NamespaceWritePolicy::NodeRestricted,
            self::Module => NamespaceWritePolicy::AccessorOnly,
        };
    }
}
