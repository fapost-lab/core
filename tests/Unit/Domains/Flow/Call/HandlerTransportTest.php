<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\Transports\HandlerTransport;
use FAPost\Foundation\Action\ActionHandlerInterface;
use FAPost\Foundation\Flow\Call\CallContext;
use FAPost\Foundation\Flow\Call\CallRequest;
use RuntimeException;
use Tests\TestCase;

final class HandlerTransportTest extends TestCase
{
    public function test_dispatches_to_action_and_wraps_result(): void
    {
        $registry = new ActionHandlerRegistry();
        $registry->register(new ProbeAction(returnPayload: ['ok' => true]));

        $transport = new HandlerTransport($registry);

        $result = $transport->execute(
            new CallRequest(target: 'probe.echo', parameters: ['x' => 1]),
            $this->context(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(['ok' => true], $result->payload);
        $this->assertSame('probe.echo', $result->metadata['action_id']);
    }

    public function test_unknown_action_returns_action_not_found(): void
    {
        $registry  = new ActionHandlerRegistry();
        $transport = new HandlerTransport($registry);

        $result = $transport->execute(
            new CallRequest(target: 'missing.action'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('action_not_found', $result->errorCode);
    }

    public function test_action_exception_is_caught_and_returned_as_error(): void
    {
        $registry = new ActionHandlerRegistry();
        $registry->register(new ProbeAction(throws: true));

        $transport = new HandlerTransport($registry);

        $result = $transport->execute(
            new CallRequest(target: 'probe.echo'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('action_exception', $result->errorCode);
        $this->assertSame(RuntimeException::class, $result->metadata['exception']);
    }

    public function test_empty_target_returns_invalid_target(): void
    {
        $transport = new HandlerTransport(new ActionHandlerRegistry());

        $result = $transport->execute(
            new CallRequest(target: '   '),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_target', $result->errorCode);
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
    ) {}

    public function id(): string
    {
        return 'probe.echo';
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
