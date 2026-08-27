<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Typing;

use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingSession;
use Fapost\Foundation\Messaging\ProcessingIndicatorHandle;
use Fapost\Foundation\Messaging\TypingCapableProviderInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class TypingHeartbeatRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_set_then_clear_returns_session_lifecycle(): void
    {
        $registry = new TypingHeartbeatRegistry();
        $session  = $this->makeSession();

        $this->assertNull($registry->current());

        $registry->set($session);
        $this->assertSame($session, $registry->current());

        $registry->clear();
        $this->assertNull($registry->current());
    }

    public function test_engine_can_refresh_via_registry_without_knowing_provider_internals(): void
    {
        $provider = Mockery::mock(TypingCapableProviderInterface::class);
        $provider->shouldReceive('refreshProcessing')->once();
        $provider->shouldReceive('stopProcessing')->zeroOrMoreTimes();

        $registry = new TypingHeartbeatRegistry();
        $registry->set(new TypingSession(
            provider: $provider,
            handle: new ProcessingIndicatorHandle('test', 'chat-1'),
            transportToken: 'token',
        ));

        // The engine treats the registry as opaque — it just tells the
        // registered session to refresh, no provider knowledge required.
        $registry->current()?->refresh();

        $this->addToAssertionCount(1); // Mockery::shouldReceive(...)->once() is the assertion.
    }

    private function makeSession(): TypingSession
    {
        /** @var TypingCapableProviderInterface&MockInterface $provider */
        $provider = Mockery::mock(TypingCapableProviderInterface::class);

        return new TypingSession(
            provider: $provider,
            handle: new ProcessingIndicatorHandle('test', 'chat'),
            transportToken: 'token',
        );
    }
}
