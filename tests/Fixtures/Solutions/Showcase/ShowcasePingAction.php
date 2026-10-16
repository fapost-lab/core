<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solutions\Showcase;

use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;

/**
 * The `showcase.ping` action, declared only by the copy of the showcase package that the testing kit's self-test installs.
 */
final class ShowcasePingAction implements ActionHandlerInterface
{
    public function id(): string
    {
        return 'showcase.ping';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return ['pong' => $parameters['word'] ?? null];
    }
}
