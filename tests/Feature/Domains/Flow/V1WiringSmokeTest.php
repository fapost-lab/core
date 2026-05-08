<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Rag\RagAdapterRegistry;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Subflow\SubflowResumerInterface;
use App\Domains\Flow\Subflow\SubflowStarterService;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use Tests\Feature\FeatureTestCase;

/**
 * V1 wiring smoke test: every Phase A→E component resolves through the
 * container and the full V1 node taxonomy is registered. A green run here
 * is a cheap signal that handlers, registries and routing services compose
 * without circular DI or missing bindings.
 */
final class V1WiringSmokeTest extends FeatureTestCase
{
    public function test_all_v1_node_handlers_are_registered(): void
    {
        $registry = $this->app->make(NodeHandlerRegistryInterface::class);

        $expected = [
            ['send_message', 1],
            ['input', 1],
            ['branch', 1],
            ['delay', 1],
            ['assign', 1],
            ['call', 1],
            ['emit_event', 1],
            ['end', 1],
            ['rag_query', 1],
            ['subflow', 1],
        ];

        foreach ($expected as [$type, $version]) {
            $handler = $registry->resolve($type, $version);
            $this->assertSame($type, $handler->type(), "Handler '{$type}' resolved to wrong type.");
        }
    }

    public function test_message_router_resolves_with_full_dependency_graph(): void
    {
        $router = $this->app->make(MessageRouter::class);
        $this->assertInstanceOf(MessageRouter::class, $router);
    }

    public function test_flow_engine_resolves_with_subflow_resumer_without_circular_di(): void
    {
        // FlowEngine ↔ SubflowResumer is a known circular dependency that's
        // broken by the Closure resolver in DefaultSubflowResumer. This test
        // is the single safety net that catches a regression.
        $engine   = $this->app->make(FlowEngineInterface::class);
        $resumer  = $this->app->make(SubflowResumerInterface::class);

        $this->assertInstanceOf(FlowEngineInterface::class, $engine);
        $this->assertInstanceOf(SubflowResumerInterface::class, $resumer);
    }

    public function test_routing_pipeline_collaborators_resolve(): void
    {
        $this->assertInstanceOf(GlobalCommandExecutorInterface::class, $this->app->make(GlobalCommandExecutorInterface::class));
        $this->assertInstanceOf(DropPolicyInterface::class, $this->app->make(DropPolicyInterface::class));
        $this->assertInstanceOf(TypingIndicatorService::class, $this->app->make(TypingIndicatorService::class));
        $this->assertInstanceOf(SubflowStarterService::class, $this->app->make(SubflowStarterService::class));
    }

    public function test_v1_registries_freeze_without_conflict(): void
    {
        $node       = $this->app->make(NodeHandlerRegistryInterface::class);
        $expression = $this->app->make(ExpressionEngineRegistry::class);
        $transport  = $this->app->make(CallTransportRegistry::class);
        $action     = $this->app->make(ActionHandlerRegistry::class);
        $rag        = $this->app->make(RagAdapterRegistry::class);

        // All shipped registries are independently freezable; in production
        // they freeze on `app->booted()`. Read-side operations stay valid.
        $this->assertNotEmpty($expression->ids());
        $this->assertNotEmpty($transport->ids());
        $this->assertSame([], $rag->providers()); // no built-in adapters in V1 core
        $this->assertNotNull($node);
        $this->assertNotNull($action);
    }

    public function test_builtin_commands_registry_ships_reset_and_cancel(): void
    {
        $registry = $this->app->make(BuiltinCommandsRegistry::class);

        $this->assertTrue($registry->has('/reset'));
        $this->assertTrue($registry->has('/cancel'));
        $this->assertFalse($registry->has('/anything-else'));
    }
}
