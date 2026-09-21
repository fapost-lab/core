<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Expression;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * End-to-end coverage for the V1.x ExpressionEngine wiring: handlers can
 * now resolve template placeholders across the full state surface — not
 * only the session JSON ({@code flow.*}, {@code system.*}, {@code rag.*})
 * but also {@code contact.*} (canonical column + attributes) and
 * {@code module.*} (registered DataAccessors) via the
 * {@code ScopedStateReader} the engine builds per node execution.
 *
 * The legacy session-only behaviour (TemplateResolver) had no way to read
 * Contact-scoped data from inside a node's template strings; flow authors
 * had to copy contact attributes into flow state first. This test demonstrates
 * the working cross-namespace resolution.
 */
final class CrossNamespaceTemplateTest extends FeatureTestCase
{
    public function test_contact_namespace_placeholders_resolve_inside_handler_templates(): void
    {
        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $tenantId, schemaName: 'main'),
        );

        $assistant = Assistant::factory()->create([
            'tenant_id'        => $tenantId,
            'default_language' => 'en',
        ]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $contact = Contact::factory()->forTenant($tenantId)->create([
            'language'   => 'es',
            'attributes' => ['first_name' => 'Alice'],
        ]);

        // Recording handler reads `template` from config, runs it through the
        // shared TemplateRenderer (engine-backed at runtime) and stores the
        // rendered string in flow.rendered for assertion.
        $this->app->make(NodeHandlerRegistry::class)
            ->register(TemplateRendererProbeHandler::class);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'cross-namespace template test',
            'nodes'     => [
                [
                    'id'      => 'p-1',
                    'type'    => 'template_probe',
                    'version' => 1,
                    'config'  => [
                        'template' => 'Hi, {{contact.first_name}} ({{contact.language}})',
                    ],
                ],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        /** @var FlowSession $session */
        $session = FlowSession::query()
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame('Hi, Alice (es)', $session->state['flow']['rendered'] ?? null);
    }

    public function test_module_namespace_placeholders_resolve_through_registered_data_accessor(): void
    {
        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $tenantId, schemaName: 'main'),
        );

        $assistant = Assistant::factory()->create([
            'tenant_id'        => $tenantId,
            'default_language' => 'en',
        ]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $accessor = new RecordingHrDataAccessor();
        $this->app->make(MutableDataAccessorRegistryInterface::class)->register('module.hr', $accessor);

        $this->app->make(NodeHandlerRegistry::class)
            ->register(TemplateRendererProbeHandler::class);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'module template test',
            'nodes'     => [
                [
                    'id'      => 'p-1',
                    'type'    => 'template_probe',
                    'version' => 1,
                    'config'  => [
                        'template' => 'Department: {{module.hr.department}}',
                    ],
                ],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        /** @var FlowSession $session */
        $session = FlowSession::query()
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame('Department: logistics', $session->state['flow']['rendered'] ?? null);
        $this->assertSame(
            [['key' => 'department', 'contactId' => (string) $contact->getKey(), 'tenantId' => $tenantId]],
            $accessor->calls,
        );
    }
}

/**
 * Module data accessor that records every lookup, so the test can prove the
 * contact and tenant coordinates reach the module.
 */
final class RecordingHrDataAccessor implements DataAccessorInterface
{
    /** @var list<array{key: string, contactId: string, tenantId: string}> */
    public array $calls = [];

    public function namespace(): string
    {
        return 'hr';
    }

    public function get(string $key, string $contactId, string $tenantId): mixed
    {
        $this->calls[] = ['key' => $key, 'contactId' => $contactId, 'tenantId' => $tenantId];

        return 'department' === $key ? 'logistics' : null;
    }

    /**
     * @return string[]
     */
    public function supportedKeys(): array
    {
        return ['department'];
    }
}

/**
 * Test handler that exercises {@see \App\Domains\Flow\Handlers\Support\TemplateRenderer}
 * inside a real engine execution — proving cross-namespace placeholders resolve
 * through the wired ScopedStateReader.
 */
final class TemplateRendererProbeHandler implements NodeHandlerInterface
{
    public function __construct(private readonly \Illuminate\Contracts\Foundation\Application $app)
    {
    }

    public function type(): string
    {
        return 'template_probe';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Template Probe';
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
        $renderer = $this->app->make(\App\Domains\Flow\Handlers\Support\TemplateRenderer::class);

        $rendered = $renderer->render(
            $nodeConfig['config']['template'] ?? '',
            $context,
            $state,
        );

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: ['flow.rendered' => $rendered],
        );
    }
}
