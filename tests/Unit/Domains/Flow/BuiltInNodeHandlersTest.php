<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\DTOs\HttpResponse;
use App\Domains\Flow\Exceptions\HttpTransportException;
use App\Domains\Flow\Handlers\ConditionNodeHandler;
use App\Domains\Flow\Handlers\DelayNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SetAttributeNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\Handlers\WebhookNodeHandler;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use Mockery;
use Tests\TestCase;

final class BuiltInNodeHandlersTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_send_message_is_idempotent_per_node_id(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->once()->withArgs(
            static fn (string $tenantId, string $contactId, string $sessionId, array $payload): bool => 'tenant-1'
                === $tenantId
                && 'contact-1' === $contactId
                && 'session-1' === $sessionId
                && 'hello' === ($payload['text'] ?? null)
                && 'Click' === ($payload['buttons'][0]['label'] ?? null)
        )->andReturn('ext-1');
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->twice()->andReturn('hello', 'Click');

        $handler = new SendMessageNodeHandler($sender, $translator);
        $context = $this->context();
        $node    = [
            'id'     => 'node-1',
            'config' => [
                'text'    => ['en' => 'hello', 'es' => 'hola'],
                'buttons' => [['label' => ['en' => 'Click', 'es' => 'Pulsa']]],
            ],
        ];

        $first  = $handler->execute($node, [], $context);
        $second = $handler->execute($node, ['system' => ['sent_messages' => ['node-1' => 'ext-1']]], $context);

        $this->assertSame(NodeExecutionStatus::Executed, $first->status);
        $this->assertSame('ext-1', $first->stateChanges[SystemStateKeys::SENT_MESSAGES]['node-1']);
        $this->assertSame(NodeExecutionStatus::Executed, $second->status);
        $this->assertSame([], $second->stateChanges);
    }

    public function test_input_waits_on_first_pass_and_executes_on_second(): void
    {
        $handler = new InputNodeHandler();
        $node    = ['id' => 'input-1', 'config' => ['save_to' => FlowStateNamespace::FLOW . '.user_name']];

        $waiting  = $handler->execute($node, [], $this->context(incoming: null));
        $executed = $handler->execute($node, [], $this->context(incoming: $this->incoming('John')));

        $this->assertSame(NodeExecutionStatus::Waiting, $waiting->status);
        $this->assertSame(NodeExecutionStatus::Executed, $executed->status);
        $this->assertSame('John', $executed->stateChanges[FlowStateNamespace::FLOW . '.user_name']);
    }

    public function test_condition_uses_state_for_flow_path_and_accessor_for_module_path(): void
    {
        $registry = Mockery::mock(DataAccessorRegistryInterface::class);
        $accessor = Mockery::mock(DataAccessorInterface::class);
        $accessor->shouldReceive('get')->once()->with('department', 'contact-1', 'tenant-1')->andReturn('sales');
        $registry->shouldReceive('resolve')->once()->with('module.hr')->andReturn($accessor);

        $handler  = new ConditionNodeHandler($registry);
        $flowNode = [
            'id'     => 'condition-flow',
            'config' => [
                'check' => FlowStateNamespace::FLOW . '.segment',
                'rules' => [['operator' => 'eq', 'value' => 'vip', 'handle' => 'yes']],
            ],
        ];
        $moduleNode = [
            'id'     => 'condition-module',
            'config' => [
                'check' => 'module.hr.department',
                'rules' => [['operator' => 'in', 'value' => ['sales', 'support'], 'handle' => 'route']],
            ],
        ];
        $emptyNode = [
            'id'     => 'condition-empty',
            'config' => [
                'check' => FlowStateNamespace::FLOW . '.optional',
                'rules' => [['operator' => 'empty', 'handle' => 'empty']],
            ],
        ];

        $flow   = $handler->execute($flowNode, [FlowStateNamespace::FLOW => ['segment' => 'vip']], $this->context());
        $module = $handler->execute($moduleNode, [], $this->context());
        $empty  = $handler->execute($emptyNode, [FlowStateNamespace::FLOW => ['optional' => '']], $this->context());

        $this->assertSame('yes', $flow->sourceHandle);
        $this->assertSame('route', $module->sourceHandle);
        $this->assertSame('empty', $empty->sourceHandle);
        $this->assertSame('module.hr.department', $module->metadata['expression']['operand'] ?? null);
        $this->assertSame('in', $module->metadata['expression']['operator'] ?? null);
    }

    public function test_delay_is_idempotent_when_already_scheduled(): void
    {
        $handler = new DelayNodeHandler();
        $node    = ['id' => 'delay-1', 'config' => ['seconds' => 15]];

        $first  = $handler->execute($node, [], $this->context(nodeId: 'delay-1'));
        $second = $handler->execute($node, [
            FlowStateNamespace::SYSTEM => ['delay' => ['delay-1' => ['scheduled_at' => '2026-01-01T00:00:00+00:00']]],
        ], $this->context(nodeId: 'delay-1'));

        $this->assertSame(NodeExecutionStatus::Waiting, $first->status);
        $this->assertNotEmpty($first->stateChanges);
        $this->assertSame(NodeExecutionStatus::Waiting, $second->status);
        $this->assertSame([], $second->stateChanges);
    }

    public function test_set_attribute_returns_contact_effect_or_flow_change(): void
    {
        $handler = new SetAttributeNodeHandler(new TemplateResolver());
        $context = $this->context();

        $contact = $handler->execute([
            'id'     => 'set-contact',
            'config' => ['target' => 'contact', 'key' => 'first_name', 'value' => '{{flow.name}}'],
        ], [FlowStateNamespace::FLOW => ['name' => 'Jane']], $context);

        $flow = $handler->execute([
            'id'     => 'set-flow',
            'config' => ['target' => 'flow', 'key' => 'nickname', 'value' => '{{flow.name}}'],
        ], [FlowStateNamespace::FLOW => ['name' => 'Jane']], $context);

        $this->assertSame('set_contact_attribute', $contact->effects[0]['type']);
        $this->assertSame('Jane', $contact->effects[0]['value']);
        $this->assertSame('Jane', $flow->stateChanges[FlowStateNamespace::FLOW . '.nickname']);
    }

    public function test_set_attribute_contact_language_returns_language_effect(): void
    {
        $handler = new SetAttributeNodeHandler(new TemplateResolver());
        $context = $this->context();

        $result = $handler->execute([
            'id'     => 'set-contact-language',
            'config' => ['target' => 'contact', 'key' => 'contact.language', 'value' => 'es'],
        ], [], $context);

        $this->assertSame('set_contact_language', $result->effects[0]['type']);
        $this->assertSame('es', $result->effects[0]['value']);

        $canonical = $handler->execute([
            'id'     => 'set-contact-language-canonical',
            'config' => ['target' => 'contact', 'key' => 'language', 'value' => 'de'],
        ], [], $context);

        $this->assertSame('set_contact_language', $canonical->effects[0]['type']);
        $this->assertSame('de', $canonical->effects[0]['value']);
    }

    public function test_webhook_returns_failed_on_transport_error_and_success_on_http_2xx(): void
    {
        $errorHttp = Mockery::mock(HttpClientInterface::class);
        $errorHttp->shouldReceive('post')->once()->andThrow(new HttpTransportException('timeout'));

        $errorHandler = new WebhookNodeHandler($errorHttp);
        $failed       = $errorHandler->execute([
            'id'     => 'hook-1',
            'config' => ['url' => 'https://example.test/hook'],
        ], [], $this->context(nodeId: 'hook-1'));

        $okHttp = Mockery::mock(HttpClientInterface::class);
        $okHttp->shouldReceive('post')->once()->andReturn(new HttpResponse(200, ['ok' => true]));

        $okHandler = new WebhookNodeHandler($okHttp);
        $executed  = $okHandler->execute([
            'id'     => 'hook-2',
            'config' => ['url' => 'https://example.test/hook', 'save_response_to' => 'flow.webhook'],
        ], [], $this->context(nodeId: 'hook-2'));

        $this->assertSame(NodeExecutionStatus::Failed, $failed->status);
        $this->assertSame('transport', $failed->metadata['error_type']);
        $this->assertSame(NodeExecutionStatus::Executed, $executed->status);
        $this->assertSame('success', $executed->sourceHandle);
    }

    public function test_template_resolver_replaces_placeholders_and_defaults_to_empty_string(): void
    {
        $resolver = new TemplateResolver();

        $resolved = $resolver->resolve('Hi, {{flow.name}} {{flow.missing}}!', ['flow' => ['name' => 'Alice']]);

        $this->assertSame('Hi, Alice !', $resolved);
    }

    private function context(?IncomingMessage $incoming = null, string $nodeId = 'node-1'): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: $nodeId,
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            incoming: $incoming,
        );
    }

    private function incoming(string $text): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'upd-1',
            externalUserId: 'ext-user',
            externalChatId: 'ext-chat',
            text: $text,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }
}
