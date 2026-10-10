<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\VariableResolver;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsageGates;
use Tests\TestCase;

final class CallNodeEgressTest extends TestCase
{
    public function test_a_denied_target_goes_to_the_error_handle_and_the_node_still_executes(): void
    {
        Http::fake();

        $registry = new CallTransportRegistry();
        $registry->register($this->app->make(HttpTransport::class));
        $handler = new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota());

        $result = $handler->execute(
            [
                'id'     => 'call-1',
                'config' => ['transport' => 'http', 'target' => 'GET http://{{flow.user_url}}/admin'],
            ],
            ['flow' => ['user_url' => '127.0.0.1:8080']],
            new NodeExecutionContext(
                tenantId: UsageGates::TENANT_ID,
                contactId: 'contact-1',
                sessionId: 'session-1',
                nodeId: 'call-1',
                idempotencyKey: 'idem-1',
                platform: 'telegram',
            ),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('egress_denied', $result->metadata['error_code']);
        Http::assertNothingSent();
    }
}
