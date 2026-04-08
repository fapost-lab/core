<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\State\Exceptions\InvalidStatePathException;
use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\Resolvers\ModuleResolutionContext;
use App\Domains\Flow\State\Resolvers\ModuleStateResolver;
use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;
use App\Domains\Flow\State\Resolvers\RagStateResolver;
use App\Domains\Flow\State\Resolvers\SessionStateResolver;
use App\Domains\Flow\State\StateNamespace;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\StateReader;
use App\Domains\Flow\State\StateWriter;
use App\Domains\Flow\State\WriteContext;
use App\Domains\Shared\Registries\ModuleNamespaceRegistry;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class StateContractTest extends TestCase
{
    public function test_state_path_parses_module_owner_and_leaf(): void
    {
        $path = StatePath::from('module.hr.department');

        $this->assertSame(StateNamespace::Module, $path->namespace);
        $this->assertSame('hr', $path->owner);
        $this->assertSame('department', $path->leaf);
        $this->assertSame('module.hr.department', $path->toString());
    }

    public function test_state_path_rejects_unknown_namespace(): void
    {
        $this->expectException(InvalidStatePathException::class);

        StatePath::from('unknown.foo');
    }

    public function test_flow_state_set_throws_when_intermediate_segment_is_scalar(): void
    {
        $state = new FlowState([
            'flow' => [
                'name' => 'Welcome',
            ],
        ]);

        $this->expectException(InvalidStatePathException::class);
        $state->set(StatePath::from('flow.name.something'), 'value');
    }

    public function test_system_namespace_is_engine_only(): void
    {
        $state    = new FlowState();
        $registry = $this->resolverRegistryWith(new TestModuleAccessor());
        $writer   = new StateWriter($registry, $state);

        $writer->set('system.current_node', 'node-1', WriteContext::engine());
        $this->assertSame('node-1', $state->get(StatePath::from('system.current_node')));

        $this->expectException(ReadonlyNamespaceException::class);
        $writer->set('system.current_node', 'node-2', WriteContext::builder());
    }

    public function test_rag_namespace_is_node_restricted_to_rag_query(): void
    {
        $state    = new FlowState();
        $registry = $this->resolverRegistryWith(new TestModuleAccessor());
        $writer   = new StateWriter($registry, $state);

        $writer->set('rag.answer', 'ok', WriteContext::node('rag_query'));
        $this->assertSame('ok', $state->get(StatePath::from('rag.answer')));

        $this->expectException(ReadonlyNamespaceException::class);
        $writer->set('rag.answer', 'forbidden', WriteContext::node('set_attribute'));
    }

    public function test_module_namespace_is_lazy_and_not_written_to_flow_state(): void
    {
        $state = new FlowState([
            'flow' => ['name' => 'Welcome Flow'],
        ]);
        $accessor = new TestModuleAccessor();
        $registry = $this->resolverRegistryWith($accessor);
        $reader   = new StateReader($registry, $state);
        $writer   = new StateWriter($registry, $state);

        $accessor->put('department', 'Sales');

        $this->assertSame('Sales', $reader->get('module.hr.department'));
        $this->assertSame(['flow' => ['name' => 'Welcome Flow']], $state->toArray());

        $this->expectException(ReadonlyNamespaceException::class);
        $writer->set('module.hr.department', 'Operations', WriteContext::accessor('hr'));
    }

    public function test_session_state_resolver_rejects_module_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use ModuleStateResolver for module namespace.');

        new SessionStateResolver(StateNamespace::Module);
    }

    public function test_session_state_resolver_rejects_rag_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use RagStateResolver for rag namespace.');

        new SessionStateResolver(StateNamespace::Rag);
    }

    public function test_namespace_resolver_registry_cannot_register_after_freeze(): void
    {
        $moduleRegistry = new ModuleNamespaceRegistry();
        $moduleRegistry->register('hr', new TestModuleAccessor());
        $moduleContext = new ModuleResolutionContext();
        $moduleContext->set('contact-1', 'tenant-1');

        $registry = new NamespaceResolverRegistry();
        $registry->register(StateNamespace::System, new SessionStateResolver(StateNamespace::System));
        $registry->register(StateNamespace::Flow, new SessionStateResolver(StateNamespace::Flow));
        $registry->register(StateNamespace::Rag, new RagStateResolver());
        $registry->register(StateNamespace::Module, new ModuleStateResolver($moduleRegistry, $moduleContext));
        $registry->freeze();

        $this->expectException(LogicException::class);
        $registry->register(StateNamespace::Flow, new SessionStateResolver(StateNamespace::Flow));
    }

    public function test_namespace_resolver_registry_freeze_requires_all_namespaces_registered(): void
    {
        $registry = new NamespaceResolverRegistry();
        $registry->register(StateNamespace::System, new SessionStateResolver(StateNamespace::System));
        $registry->register(StateNamespace::Flow, new SessionStateResolver(StateNamespace::Flow));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("No resolver registered for namespace 'rag'");
        $registry->freeze();
    }

    public function test_module_state_resolver_throws_invalid_state_path_for_missing_owner(): void
    {
        $state          = new FlowState();
        $moduleRegistry = new ModuleNamespaceRegistry();
        $moduleContext  = new ModuleResolutionContext();
        $moduleContext->set('contact-1', 'tenant-1');
        $resolver = new ModuleStateResolver($moduleRegistry, $moduleContext);
        $path     = new StatePath(StateNamespace::Module, null, 'department');

        $this->expectException(InvalidStatePathException::class);
        $resolver->get($path, $state);
    }

    public function test_module_namespace_registry_cannot_register_after_freeze(): void
    {
        $moduleRegistry = new ModuleNamespaceRegistry();
        $moduleRegistry->register('hr', new TestModuleAccessor());
        $moduleRegistry->freeze();

        $this->expectException(LogicException::class);
        $moduleRegistry->register('crm', new TestModuleAccessor());
    }

    private function resolverRegistryWith(TestModuleAccessor $accessor): NamespaceResolverRegistry
    {
        $moduleRegistry = new ModuleNamespaceRegistry();
        $moduleRegistry->register('hr', $accessor);
        $moduleContext = new ModuleResolutionContext();
        $moduleContext->set('contact-1', 'tenant-1');

        $registry = new NamespaceResolverRegistry();
        $registry->register(StateNamespace::System, new SessionStateResolver(StateNamespace::System));
        $registry->register(StateNamespace::Flow, new SessionStateResolver(StateNamespace::Flow));
        $registry->register(StateNamespace::Rag, new RagStateResolver());
        $registry->register(StateNamespace::Module, new ModuleStateResolver($moduleRegistry, $moduleContext));

        return $registry;
    }
}

final class TestModuleAccessor implements DataAccessorInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function namespace(): string
    {
        return 'hr';
    }

    public function get(string $key, string $contactId, string $tenantId): mixed
    {
        if ('contact-1' !== $contactId || 'tenant-1' !== $tenantId) {
            return null;
        }

        return $this->data[$key] ?? null;
    }

    /**
     * @return string[]
     */
    public function supportedKeys(): array
    {
        return array_keys($this->data);
    }

}
