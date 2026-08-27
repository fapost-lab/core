<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\StateNamespaceViolationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * Covers the execution-loop safety net: unhandled handler exceptions must mark the
 * session as failed (observable crash), and handler state writes must respect the
 * namespace contract enforced by SystemStateNamespacePolicy.
 */
final class FlowEngineHandlerFailureTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(new ThrowingFlowTestHandler());
        $registry->register(new ForbiddenNamespaceWriteTestHandler());
    }

    public function test_handler_exception_marks_session_failed_and_rethrows(): void
    {
        $definition = $this->definition('throwing_test');
        $contact    = $this->prepareRuntime($definition->tenant_id);

        $engine = $this->app->make(FlowEngineInterface::class);

        try {
            $engine->start($definition, $contact);
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler exploded', $exception->getMessage());
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();
        $this->assertSame(FlowSessionStatus::Failed, $session->status);

        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('throwing_test', $log->node_type);
        $this->assertStringContainsString('handler exploded', (string) $log->error);
    }

    public function test_forbidden_system_namespace_write_is_rejected_at_persist(): void
    {
        $definition = $this->definition('forbidden_write_test');
        $contact    = $this->prepareRuntime($definition->tenant_id);

        $engine = $this->app->make(FlowEngineInterface::class);

        $this->expectException(StateNamespaceViolationException::class);

        $engine->start($definition, $contact);
    }

    private function definition(string $nodeType): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => (string) Str::uuid(),
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Failure path',
            'nodes'     => [
                ['id' => 'n1', 'type' => $nodeType, 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    private function prepareRuntime(string $tenantId): Contact
    {
        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return $contact;
    }
}

final class ThrowingFlowTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'throwing_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Throwing Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        throw new RuntimeException('handler exploded');
    }
}

final class ForbiddenNamespaceWriteTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'forbidden_write_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Forbidden Write Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::finished(
            stateChanges: ['system.hijacked' => true],
        );
    }
}
