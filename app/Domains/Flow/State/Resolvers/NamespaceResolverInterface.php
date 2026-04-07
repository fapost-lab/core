<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;

interface NamespaceResolverInterface
{
    public function get(StatePath $path, FlowState $state): mixed;

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void;
}
