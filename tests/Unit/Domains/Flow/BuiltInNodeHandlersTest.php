<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\DTOs\HttpResponse;
use App\Domains\Flow\Exceptions\HttpTransportException;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\ConditionNodeHandler;
use App\Domains\Flow\Handlers\DelayNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SetAttributeNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\Handlers\WebhookNodeHandler;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
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
                && 'text' === ($payload['content_type'] ?? null)
                && 'hello' === ($payload['text'] ?? null)
        )->andReturn('ext-1');
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->once()->andReturn('hello');

        $handler = $this->makeHandler($sender, $translator);
        $context = $this->context();
        $node    = [
            'id'     => 'node-1',
            'config' => [
                'content_type' => 'text',
                'text'         => ['en' => 'hello', 'es' => 'hola'],
            ],
        ];

        $first  = $handler->execute($node, [], $context);
        $second = $handler->execute($node, ['system' => ['sent_messages' => ['node-1' => 'ext-1']]], $context);

        $this->assertSame(NodeExecutionStatus::Executed, $first->status);
        $this->assertSame('ext-1', $first->stateChanges[SystemStateKeys::SENT_MESSAGES]['node-1']);
        $this->assertSame(NodeExecutionStatus::Executed, $second->status);
        $this->assertSame([], $second->stateChanges);
    }

    public function test_send_message_inline_keyboard_waits_and_resumes_with_button_value(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->once()->andReturn('ext-inline');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->times(3)->andReturn('Choose', 'Yes', 'No');

        $sessionUuid = '00000000-0000-4000-8000-000000000001';
        $buttonUuid  = '11111111-1111-4111-8111-111111111111';
        $cbData      = CallbackDataCodec::encode($sessionUuid, $buttonUuid);

        $handler = $this->makeHandler($sender, $translator);
        $node    = [
            'id'     => 'node-inline',
            'config' => [
                'content_type'                => 'text_with_keyboard',
                'keyboard_mode'               => 'inline',
                'remove_keyboard_after_press' => false,
                'text'                        => ['en' => 'Choose'],
                'buttons'                     => [
                    ['id' => $buttonUuid, 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'label' => ['en' => 'No'], 'value' => 'no', 'row' => 0, 'order' => 1],
                ],
            ],
        ];

        $waiting = $handler->execute($node, [], $this->context(nodeId: 'node-inline', sessionId: $sessionUuid));

        $resume = $handler->execute(
            $node,
            [
                'system' => [
                    'sent_messages' => ['node-inline' => 'ext-inline'],
                ],
            ],
            $this->context(
                incoming: new IncomingMessage(
                    updateId: 'cb-1',
                    externalUserId: 'ext-user',
                    externalChatId: 'ext-chat',
                    text: $cbData,
                    type: IncomingMessageType::CallbackQuery,
                    platform: 'telegram',
                ),
                nodeId: 'node-inline',
                sessionId: $sessionUuid,
            ),
        );

        $this->assertSame(NodeExecutionStatus::Waiting, $waiting->status);
        $this->assertSame(NodeExecutionStatus::Executed, $resume->status);
        $this->assertSame($buttonUuid, $resume->sourceHandle);
    }

    public function test_send_message_throws_for_missing_content_type(): void
    {
        $sender     = Mockery::mock(MessageSenderInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler = $this->makeHandler($sender, $translator);

        $this->expectException(InvalidNodeConfigException::class);

        $handler->execute([
            'id'     => 'node-invalid',
            'config' => ['text' => ['en' => 'Hello']],
        ], [], $this->context(nodeId: 'node-invalid'));
    }

    public function test_send_message_reply_keyboard_fires_and_forgets(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->once()->andReturn('ext-reply');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->times(2)->andReturn('Pick one', 'Option A');

        $handler = $this->makeHandler($sender, $translator);
        $node    = [
            'id'     => 'node-reply',
            'config' => [
                'content_type'  => 'text_with_keyboard',
                'keyboard_mode' => 'reply',
                'text'          => ['en' => 'Pick one'],
                'buttons'       => [
                    ['id' => '33333333-3333-4333-8333-333333333333', 'label' => ['en' => 'Option A'], 'value' => 'a', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $result = $handler->execute($node, [], $this->context(nodeId: 'node-reply'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertArrayHasKey('node-reply', $result->stateChanges[SystemStateKeys::SENT_MESSAGES]);
    }

    public function test_send_message_inline_keyboard_idempotent_skip_still_waits(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldNotReceive('send');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler = $this->makeHandler($sender, $translator);
        $node       = [
            'id'     => 'node-inline',
            'config' => [
                'content_type'                => 'text_with_keyboard',
                'keyboard_mode'               => 'inline',
                'remove_keyboard_after_press' => false,
                'text'                        => ['en' => 'Choose'],
                'buttons'                     => [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            ['system' => ['sent_messages' => ['node-inline' => 'ext-inline']]],
            $this->context(nodeId: 'node-inline'),
        );

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([], $result->stateChanges);
    }

    public function test_send_message_inline_timeout_routes_to_no_response(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldNotReceive('send');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler = $this->makeHandler($sender, $translator);
        $node       = [
            'id'     => 'node-inline',
            'config' => [
                'content_type'                => 'text_with_keyboard',
                'keyboard_mode'               => 'inline',
                'remove_keyboard_after_press' => false,
                'text'                        => ['en' => 'Choose'],
                'buttons'                     => [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            ['system' => ['sent_messages' => ['node-inline' => 'ext-inline']]],
            $this->context(
                incoming: new IncomingMessage(
                    updateId: 'timeout-upd-1',
                    externalUserId: 'ext-user',
                    externalChatId: 'ext-chat',
                    text: null,
                    type: IncomingMessageType::Text,
                    platform: 'telegram',
                    payload: ['send_message_timeout' => true],
                ),
                nodeId: 'node-inline',
            ),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('no_response', $result->sourceHandle);
    }

    public function test_send_message_image_executes_and_stores_sent_id(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->once()->withArgs(
            static fn (string $tenantId, string $contactId, string $sessionId, array $payload): bool => 'image' === ($payload['content_type'] ?? null)
                                                                                                        && 'media-file-1'
                                                                                                           === ($payload['media_file_id']
                                                                                                                ?? null)
        )->andReturn('ext-img');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->once()->andReturn('Caption text');

        $handler = $this->makeHandler($sender, $translator);
        $result  = $handler->execute([
            'id'     => 'node-image',
            'config' => [
                'content_type'  => 'image',
                'media_file_id' => 'media-file-1',
                'caption'       => ['en' => 'Caption text'],
            ],
        ], [], $this->context(nodeId: 'node-image'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('ext-img', $result->stateChanges[SystemStateKeys::SENT_MESSAGES]['node-image']);
    }

    public function test_send_message_throws_for_image_without_media_file_id(): void
    {
        $sender     = Mockery::mock(MessageSenderInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler = $this->makeHandler($sender, $translator);

        $this->expectException(InvalidNodeConfigException::class);
        $this->expectExceptionMessage('media_file_id');

        $handler->execute([
            'id'     => 'node-img-bad',
            'config' => ['content_type' => 'image'],
        ], [], $this->context(nodeId: 'node-img-bad'));
    }

    public function test_send_message_strips_legacy_media_url_and_requires_media_file_id(): void
    {
        $sender     = Mockery::mock(MessageSenderInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler    = $this->makeHandler($sender, $translator);

        // legacy media_url is silently stripped; image without media_file_id fails on the next check
        $this->expectException(InvalidNodeConfigException::class);
        $this->expectExceptionMessage('media_file_id');

        $handler->execute([
            'id'     => 'node-img-legacy',
            'config' => [
                'content_type' => 'image',
                'media_url'    => 'https://example.com/img.jpg',
            ],
        ], [], $this->context(nodeId: 'node-img-legacy'));
    }

    public function test_send_message_throws_for_text_with_keyboard_missing_keyboard_mode(): void
    {
        $sender     = Mockery::mock(MessageSenderInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $handler = $this->makeHandler($sender, $translator);

        $this->expectException(InvalidNodeConfigException::class);
        $this->expectExceptionMessage('keyboard_mode');

        $handler->execute([
            'id'     => 'node-kb-bad',
            'config' => [
                'content_type' => 'text_with_keyboard',
                'text'         => ['en' => 'Hello'],
                'buttons'      => [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ], [], $this->context(nodeId: 'node-kb-bad'));
    }

    public function test_input_waits_on_first_pass_and_executes_on_second(): void
    {
        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
        );
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

    private function makeHandler(
        MessageSenderInterface $sender,
        ContentTranslatorInterface $translator,
    ): SendMessageNodeHandler {
        return new SendMessageNodeHandler(
            $sender,
            $translator,
            new TemplateResolver(),
            Mockery::mock(InlineKeyboardEditorInterface::class),
            Mockery::mock(PersistentButtonRegistryInterface::class),
        );
    }

    private function context(
        ?IncomingMessage $incoming = null,
        string $nodeId = 'node-1',
        string $sessionId = 'session-1',
    ): NodeExecutionContext {
        return new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: $sessionId,
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
