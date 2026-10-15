<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\Transports\HandlerTransport;
use App\Domains\Flow\Contracts\ActionAvailabilityInterface;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use RuntimeException;
use Tests\TestCase;

final class HandlerTransportTest extends TestCase
{
    public function test_dispatches_to_action_and_wraps_result(): void
    {
        $this->app->instance(ProbeAction::class, new ProbeAction(returnPayload: ['ok' => true]));
        $registry = $this->registry();
        $registry->register(ProbeAction::class);

        $transport = $this->transport($registry);

        $result = $transport->execute(
            new CallRequest(target: 'core.probe', parameters: ['x' => 1]),
            $this->context(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(['ok' => true], $result->payload);
        $this->assertSame('core.probe', $result->metadata['action_id']);
    }

    public function test_unknown_action_returns_action_not_found(): void
    {
        $transport = $this->transport($this->registry());

        $result = $transport->execute(
            new CallRequest(target: 'missing.action'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('action_not_found', $result->errorCode);
    }

    public function test_action_exception_is_caught_and_returned_as_error(): void
    {
        $this->app->instance(ProbeAction::class, new ProbeAction(throws: true));
        $registry = $this->registry();
        $registry->register(ProbeAction::class);

        $transport = $this->transport($registry);

        $result = $transport->execute(
            new CallRequest(target: 'core.probe'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('action_exception', $result->errorCode);
        $this->assertSame(RuntimeException::class, $result->metadata['exception']);
    }

    public function test_empty_target_returns_invalid_target(): void
    {
        $transport = $this->transport($this->registry());

        $result = $transport->execute(
            new CallRequest(target: '   '),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_target', $result->errorCode);
    }

    public function test_an_inactive_solutions_action_is_refused_and_its_handler_is_not_built(): void
    {
        $constructed = 0;
        $this->app->bind(ProbeAction::class, static function () use (&$constructed): ProbeAction {
            ++$constructed;

            return new ProbeAction(returnPayload: ['ok' => true]);
        });
        $registry = $this->registry();
        $registry->register(ProbeAction::class, 'feedback');
        $constructed = 0;

        $refused = $this->transport($registry, available: false)->execute(new CallRequest(target: 'core.probe'), $this->context());

        $this->assertFalse($refused->success);
        $this->assertSame('solution_inactive', $refused->errorCode);
        $this->assertSame(['action_id' => 'core.probe', 'solution_id' => 'feedback'], $refused->metadata);
        $this->assertSame(0, $constructed, 'The handler is not built for a refused call.');

        $this->assertTrue($this->transport($registry, available: true)->execute(new CallRequest(target: 'core.probe'), $this->context())->success);
    }
    private function registry(): ActionHandlerRegistry
    {
        return new ActionHandlerRegistry(new ContainerActionHandlerFactory($this->app));
    }

    private function transport(ActionHandlerRegistry $registry, bool $available = true): HandlerTransport
    {
        return new HandlerTransport($registry, new class ($available) implements ActionAvailabilityInterface {
            public function __construct(private readonly bool $available)
            {
            }

            public function isAvailable(?string $solutionId): bool
            {
                return null === $solutionId || $this->available;
            }
        });
    }

    private function context(): CallContext
    {
        return new CallContext(
            tenantId: 't-1',
            contactId: 'c-1',
            sessionId: 'session-1',
            nodeId: 'node-1',
            idempotencyKey: 'session-1:node-1:1',
        );
    }
}

final class ProbeAction implements ActionHandlerInterface
{
    /**
     * @param  array<string, mixed>  $returnPayload
     */
    public function __construct(
        private readonly array $returnPayload = [],
        private readonly bool $throws = false,
    ) {
    }

    public function id(): string
    {
        return 'core.probe';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        if ($this->throws) {
            throw new RuntimeException('boom');
        }

        return $this->returnPayload;
    }
}
