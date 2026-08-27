<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Handlers\AuthRequestNodeHandler;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Contracts\ContactWriterInterface;
use Mockery;
use Tests\TestCase;

final class AuthRequestNodeHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_match_raises_auth_flag_and_continues(): void
    {
        $writer = Mockery::mock(ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.is_authenticated', true);

        $result = $this->handler()->execute(
            $this->node(['method' => 'basic', 'variable' => 'flow.code', 'operator' => 'eq', 'value' => '1234']),
            ['flow' => ['code' => '1234']],
            $this->context($writer),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        // Single output — the node never branches.
        $this->assertSame('default', $result->sourceHandle);
        $this->assertTrue($result->metadata['authenticated']);
    }

    public function test_mismatch_continues_without_raising_flag(): void
    {
        $writer = Mockery::mock(ContactWriterInterface::class);
        $writer->shouldNotReceive('write');

        $result = $this->handler()->execute(
            $this->node(['method' => 'basic', 'variable' => 'flow.code', 'operator' => 'eq', 'value' => '1234']),
            ['flow' => ['code' => '0000']],
            $this->context($writer),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertFalse($result->metadata['authenticated']);
    }

    public function test_not_empty_operator_authenticates_when_value_present(): void
    {
        $writer = Mockery::mock(ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.is_authenticated', true);

        $result = $this->handler()->execute(
            $this->node(['method' => 'basic', 'variable' => 'flow.token', 'operator' => 'not_empty']),
            ['flow' => ['token' => 'abc']],
            $this->context($writer),
        );

        $this->assertSame('default', $result->sourceHandle);
    }

    public function test_neq_operator_authenticates_when_values_differ(): void
    {
        $writer = Mockery::mock(ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.is_authenticated', true);

        $result = $this->handler()->execute(
            $this->node(['method' => 'basic', 'variable' => 'flow.role', 'operator' => 'neq', 'value' => 'guest']),
            ['flow' => ['role' => 'admin']],
            $this->context($writer),
        );

        $this->assertSame('default', $result->sourceHandle);
    }

    public function test_contains_operator_with_templated_expected_value(): void
    {
        $writer = Mockery::mock(ContactWriterInterface::class);
        $writer->shouldReceive('write')->once();

        $result = $this->handler()->execute(
            $this->node(['method' => 'basic', 'variable' => 'flow.email', 'operator' => 'contains', 'value' => '{{flow.domain}}']),
            ['flow' => ['email' => 'user@acme.com', 'domain' => 'acme.com']],
            $this->context($writer),
        );

        $this->assertSame('default', $result->sourceHandle);
    }

    private function handler(): AuthRequestNodeHandler
    {
        return new AuthRequestNodeHandler(
            new OperandResolver(
                app(DataAccessorRegistryInterface::class),
                app(VariableResolverInterface::class),
            ),
            new OperatorComparator(),
            app(TemplateRenderer::class),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function node(array $config): array
    {
        return ['id' => 'node-auth', 'type' => 'auth_request', 'config' => $config];
    }

    private function context(ContactWriterInterface $writer): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: '00000000-0000-0000-0000-000000000001',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'node-auth',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            contactWriter: $writer,
        );
    }
}
