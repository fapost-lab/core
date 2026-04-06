<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\DTO\NodeExecutionContext;
use App\Domains\Flow\DTO\NodeExecutionResult;

interface NodeHandlerInterface
{
    public function type(): string;

    public function version(): int;

    /**
     * @return array<int>
     */
    public function supportedVersions(): array;

    public function handle(NodeExecutionContext $context): NodeExecutionResult;
}
