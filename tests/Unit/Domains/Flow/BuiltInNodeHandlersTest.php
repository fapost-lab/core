<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\DTOs\HttpResponse;
use App\Domains\Flow\Exceptions\HttpTransportException;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\AssignNodeHandler;
use App\Domains\Flow\Handlers\BranchNodeHandler;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\DelayNodeHandler;
use App\Domains\Flow\Handlers\EmitEventNodeHandler;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\RagQueryNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Rag\RagAdapterRegistry;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\VariableResolver;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use FAPost\Foundation\Contracts\RagAdapterInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\DTO\RagConfidence;
use FAPost\Foundation\DTO\RagQueryContext;
use FAPost\Foundation\DTO\StructuredRagResult;
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
            new VariableResolver(),
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

        $handler  = new BranchNodeHandler($registry, new VariableResolver());
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

    public function test_condition_resolves_user_variable_left_via_variable_resolver(): void
    {
        $registry = Mockery::mock(DataAccessorRegistryInterface::class);
        $handler  = new BranchNodeHandler($registry, new VariableResolver());

        // Session-scoped variable: {storage:session, name:code} → flow.code
        $sessionNode = [
            'id'     => 'cond-session',
            'config' => [
                'rules' => [
                    [
                        'left' => [
                            'ref'      => 'user_variable',
                            'variable' => ['name' => 'code', 'storage' => 'session', 'group' => null],
                        ],
                        'operator' => 'eq',
                        'value'    => '1234',
                        'handle'   => 'yes',
                    ],
                ],
            ],
        ];

        $session = $handler->execute(
            $sessionNode,
            [FlowStateNamespace::FLOW => ['code' => '1234']],
            $this->context(),
        );

        $this->assertSame('yes', $session->sourceHandle);

        // Contact-grouped variable: {storage:contact, group:survey, name:score} → contact.survey.score
        $contactNode = [
            'id'     => 'cond-contact',
            'config' => [
                'rules' => [
                    [
                        'left' => [
                            'ref'      => 'user_variable',
                            'variable' => ['name' => 'score', 'storage' => 'contact', 'group' => 'survey'],
                        ],
                        'operator' => 'gt',
                        'value'    => 5,
                        'handle'   => 'yes',
                    ],
                ],
            ],
        ];

        $contact = $handler->execute(
            $contactNode,
            ['contact' => ['survey' => ['score' => 7]]],
            $this->context(),
        );

        $this->assertSame('yes', $contact->sourceHandle);
    }

    public function test_condition_resolves_source_left_for_known_namespaces(): void
    {
        $registry = Mockery::mock(DataAccessorRegistryInterface::class);
        $handler  = new BranchNodeHandler($registry, new VariableResolver());

        $node = [
            'id'     => 'cond-rag',
            'config' => [
                'rules' => [
                    [
                        'left'     => ['ref' => 'source', 'source' => 'rag', 'field' => 'found'],
                        'operator' => 'eq',
                        'value'    => true,
                        'handle'   => 'route',
                    ],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            [FlowStateNamespace::RAG => ['found' => true]],
            $this->context(),
        );

        $this->assertSame('route', $result->sourceHandle);
        $this->assertSame('rag.found', $result->metadata['expression']['operand'] ?? null);
    }

    public function test_condition_legacy_string_left_falls_back_to_check(): void
    {
        $registry = Mockery::mock(DataAccessorRegistryInterface::class);
        $handler  = new BranchNodeHandler($registry, new VariableResolver());

        $node = [
            'id'     => 'cond-legacy',
            'config' => [
                'check' => FlowStateNamespace::FLOW . '.code',
                'rules' => [
                    ['operator' => 'eq', 'value' => '1234', 'handle' => 'yes'],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            [FlowStateNamespace::FLOW => ['code' => '1234']],
            $this->context(),
        );

        $this->assertSame('yes', $result->sourceHandle);
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

    public function test_assign_writes_to_flow_state_or_contact_writer_by_target(): void
    {
        $handler = new AssignNodeHandler(new TemplateRenderer(), new VariableResolver());

        $writer = Mockery::mock(\FAPost\Foundation\Flow\Contracts\ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.first_name', 'Jane');

        $contextWithWriter = $this->contextWithContactWriter($writer);

        $handler->execute([
            'id'     => 'set-contact',
            'config' => ['target' => 'contact', 'key' => 'first_name', 'value' => '{{flow.name}}'],
        ], [FlowStateNamespace::FLOW => ['name' => 'Jane']], $contextWithWriter);

        $flow = $handler->execute([
            'id'     => 'set-flow',
            'config' => ['target' => 'flow', 'key' => 'nickname', 'value' => '{{flow.name}}'],
        ], [FlowStateNamespace::FLOW => ['name' => 'Jane']], $this->context());

        $this->assertSame('Jane', $flow->stateChanges[FlowStateNamespace::FLOW . '.nickname']);
    }

    public function test_assign_contact_language_aliases_route_to_canonical_path(): void
    {
        $handler = new AssignNodeHandler(new TemplateRenderer(), new VariableResolver());

        $writer = Mockery::mock(\FAPost\Foundation\Flow\Contracts\ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.language', 'es');
        $writer->shouldReceive('write')->once()->with('contact.language', 'de');

        $context = $this->contextWithContactWriter($writer);

        $handler->execute([
            'id'     => 'set-contact-language',
            'config' => ['target' => 'contact', 'key' => 'contact.language', 'value' => 'es'],
        ], [], $context);

        $handler->execute([
            'id'     => 'set-contact-language-canonical',
            'config' => ['target' => 'contact', 'key' => 'language', 'value' => 'de'],
        ], [], $context);

        $this->addToAssertionCount(1); // mockery expectations are the assertion
    }

    public function test_input_with_legacy_save_to_writes_to_flow_namespace(): void
    {
        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
        );

        $node = ['id' => 'input-legacy', 'config' => ['save_to' => 'user_name']];

        $result = $handler->execute($node, [], $this->context(incoming: $this->incoming('Alice')));

        $this->assertSame('Alice', $result->stateChanges['flow.user_name']);
    }

    public function test_input_with_new_variable_session_storage(): void
    {
        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
        );

        $node = [
            'id'     => 'input-new',
            'config' => [
                'variable' => ['name' => 'phone', 'storage' => 'session'],
            ],
        ];

        $result = $handler->execute($node, [], $this->context(incoming: $this->incoming('+1234')));

        $this->assertSame('+1234', $result->stateChanges['flow.phone']);
    }

    public function test_input_with_new_variable_contact_storage_writes_through_writer(): void
    {
        $writer = Mockery::mock(\FAPost\Foundation\Flow\Contracts\ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.profile.first_name', 'Jane');

        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
        );

        $node = [
            'id'     => 'input-contact',
            'config' => [
                'variable' => ['name' => 'first_name', 'storage' => 'contact', 'group' => 'profile'],
            ],
        ];

        $context = new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'input-contact',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            incoming: $this->incoming('Jane'),
            contactWriter: $writer,
        );

        $result = $handler->execute($node, [], $context);

        $this->assertSame([], $result->stateChanges);
    }

    public function test_assign_with_operations_writes_session_and_contact_in_one_node(): void
    {
        $writer = Mockery::mock(\FAPost\Foundation\Flow\Contracts\ContactWriterInterface::class);
        $writer->shouldReceive('write')->once()->with('contact.first_name', 'Jane');

        $handler = new AssignNodeHandler(new TemplateRenderer(), new VariableResolver());

        $node = [
            'id'     => 'assign-multi',
            'config' => [
                'operations' => [
                    [
                        'variable' => ['name' => 'first_name', 'storage' => 'contact'],
                        'value'    => '{{flow.name}}',
                    ],
                    [
                        'variable' => ['name' => 'tier', 'storage' => 'session'],
                        'value'    => 'gold',
                    ],
                ],
            ],
        ];

        $context = new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'assign-multi',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            contactWriter: $writer,
        );

        $result = $handler->execute(
            $node,
            [FlowStateNamespace::FLOW => ['name' => 'Jane']],
            $context,
        );

        $this->assertSame('gold', $result->stateChanges['flow.tier']);
    }

    public function test_send_message_save_to_variable_writes_button_value_via_resolver(): void
    {
        $sessionUuid = '00000000-0000-4000-8000-000000000099';
        $buttonUuid  = '11111111-1111-4111-8111-111111111199';
        $cbData      = CallbackDataCodec::encode($sessionUuid, $buttonUuid);

        $sender = Mockery::mock(MessageSenderInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $keyboardEditor = Mockery::mock(InlineKeyboardEditorInterface::class);
        $keyboardEditor->shouldReceive('removeKeyboard')->zeroOrMoreTimes();

        $handler = new SendMessageNodeHandler(
            $sender,
            $translator,
            new TemplateRenderer(),
            $keyboardEditor,
            Mockery::mock(PersistentButtonRegistryInterface::class),
            new VariableResolver(),
        );

        $context = new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: $sessionUuid,
            nodeId: 'sm-1',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            incoming: new IncomingMessage(
                updateId: 'upd-99',
                externalUserId: 'ext-user',
                externalChatId: 'ext-chat',
                text: $cbData,
                type: IncomingMessageType::CallbackQuery,
                platform: 'telegram',
            ),
        );

        $node = [
            'id'     => 'sm-1',
            'config' => [
                'content_type'    => 'text_with_keyboard',
                'text'            => ['en' => 'pick'],
                'keyboard_mode'   => 'inline',
                'save_to_variable' => ['name' => 'choice', 'storage' => 'session'],
                'buttons'         => [
                    ['id' => $buttonUuid, 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $state = ['system' => ['sent_messages' => ['sm-1' => 'ext-99']]];

        $result = $handler->execute($node, $state, $context);

        $this->assertSame('yes', $result->stateChanges['flow.choice']);
    }

    public function test_send_message_legacy_save_to_still_works(): void
    {
        $sessionUuid = '00000000-0000-4000-8000-000000000098';
        $buttonUuid  = '11111111-1111-4111-8111-111111111198';
        $cbData      = CallbackDataCodec::encode($sessionUuid, $buttonUuid);

        $sender         = Mockery::mock(MessageSenderInterface::class);
        $translator     = Mockery::mock(ContentTranslatorInterface::class);
        $keyboardEditor = Mockery::mock(InlineKeyboardEditorInterface::class);
        $keyboardEditor->shouldReceive('removeKeyboard')->zeroOrMoreTimes();

        $handler = new SendMessageNodeHandler(
            $sender,
            $translator,
            new TemplateRenderer(),
            $keyboardEditor,
            Mockery::mock(PersistentButtonRegistryInterface::class),
            new VariableResolver(),
        );

        $context = new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: $sessionUuid,
            nodeId: 'sm-2',
            idempotencyKey: 'idem-2',
            platform: 'telegram',
            incoming: new IncomingMessage(
                updateId: 'upd-98',
                externalUserId: 'ext-user',
                externalChatId: 'ext-chat',
                text: $cbData,
                type: IncomingMessageType::CallbackQuery,
                platform: 'telegram',
            ),
        );

        $node = [
            'id'     => 'sm-2',
            'config' => [
                'content_type'  => 'text_with_keyboard',
                'text'          => ['en' => 'pick'],
                'keyboard_mode' => 'inline',
                'save_to'       => 'pick',
                'buttons'       => [
                    ['id' => $buttonUuid, 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $state = ['system' => ['sent_messages' => ['sm-2' => 'ext-98']]];

        $result = $handler->execute($node, $state, $context);

        $this->assertSame('yes', $result->stateChanges['flow.pick']);
    }

    public function test_webhook_returns_failed_on_transport_error_and_success_on_http_2xx(): void
    {
        $errorHttp = Mockery::mock(HttpClientInterface::class);
        $errorHttp->shouldReceive('post')->once()->andThrow(new HttpTransportException('timeout'));

        $errorHandler = new CallNodeHandler($errorHttp);
        $failed       = $errorHandler->execute([
            'id'     => 'hook-1',
            'config' => ['url' => 'https://example.test/hook'],
        ], [], $this->context(nodeId: 'hook-1'));

        $okHttp = Mockery::mock(HttpClientInterface::class);
        $okHttp->shouldReceive('post')->once()->andReturn(new HttpResponse(200, ['ok' => true]));

        $okHandler = new CallNodeHandler($okHttp);
        $executed  = $okHandler->execute([
            'id'     => 'hook-2',
            'config' => ['url' => 'https://example.test/hook', 'save_response_to' => 'flow.webhook'],
        ], [], $this->context(nodeId: 'hook-2'));

        $this->assertSame(NodeExecutionStatus::Failed, $failed->status);
        $this->assertSame('transport', $failed->metadata['error_type']);
        $this->assertSame(NodeExecutionStatus::Executed, $executed->status);
        $this->assertSame('success', $executed->sourceHandle);
    }

    public function test_webhook_forwards_custom_headers_and_protects_reserved_ones(): void
    {
        $capturedHeaders = [];
        $http            = Mockery::mock(HttpClientInterface::class);
        $http->shouldReceive('post')
            ->once()
            ->andReturnUsing(
                function (string $url, array $payload, array $headers, int $timeout) use (&$capturedHeaders) {
                    $capturedHeaders = $headers;

                    return new HttpResponse(200, ['ok' => true]);
                }
            );

        $handler = new CallNodeHandler($http);
        $handler->execute([
            'id'     => 'hook-1',
            'config' => [
                'url'     => 'https://example.test/hook',
                'headers' => [
                    'Authorization'     => 'Bearer abc',
                    'Accept-Language'   => 'fr-FR',
                    'X-Idempotency-Key' => 'attacker-controlled',
                ],
            ],
        ], [], $this->context(nodeId: 'hook-1'));

        $this->assertSame('Bearer abc', $capturedHeaders['Authorization']);
        $this->assertSame('fr-FR', $capturedHeaders['Accept-Language']);
        // Engine-set headers always win, even when the author tried to override them.
        $this->assertSame('session-1:hook-1', $capturedHeaders['X-Idempotency-Key']);
        $this->assertSame('session-1', $capturedHeaders['X-FAPost-Session']);
    }

    public function test_emit_event_publishes_resolved_payload_and_returns_success_handle(): void
    {
        $publisher = Mockery::mock(FlowTriggerEventPublisherInterface::class);
        $publisher->shouldReceive('publish')
            ->once()
            ->with(
                'tenant-1',
                'sales.order.created',
                ['order_id' => 'ORD-7', 'amount' => '199', 'meta' => ['source' => 'web']],
                Mockery::on(static function (array $source): bool {
                    return 'tenant-1' === $source['tenant_id']
                        && 'session-1' === $source['session_id']
                        && 'emit-1' === $source['node_id']
                        && 'contact-1' === $source['contact_id'];
                })
            );

        $handler = new EmitEventNodeHandler($publisher, new TemplateRenderer());
        $context = $this->context(nodeId: 'emit-1');

        $result = $handler->execute([
            'id'     => 'emit-1',
            'config' => [
                'event_type' => 'sales.order.created',
                'payload'    => [
                    'order_id' => '{{flow.order_id}}',
                    'amount'   => '{{flow.amount}}',
                    'meta'     => ['source' => 'web'],
                ],
            ],
        ], [
            FlowStateNamespace::FLOW => ['order_id' => 'ORD-7', 'amount' => 199],
        ], $context);

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame('sales.order.created', $result->metadata['event_type']);
    }

    public function test_emit_event_throws_when_event_type_is_missing(): void
    {
        $publisher = Mockery::mock(FlowTriggerEventPublisherInterface::class);
        $publisher->shouldNotReceive('publish');

        $handler = new EmitEventNodeHandler($publisher, new TemplateRenderer());

        $this->expectException(InvalidNodeConfigException::class);

        $handler->execute(['id' => 'emit-x', 'config' => ['event_type' => '   ']], [], $this->context());
    }

    public function test_end_handler_returns_finished_with_chosen_status(): void
    {
        $handler = new EndNodeHandler();

        $success = $handler->execute(['id' => 'end-1', 'config' => ['status' => 'success']], [], $this->context());
        $this->assertSame(NodeExecutionStatus::Finished, $success->status);
        $this->assertSame('success', $success->metadata['end_status']);

        $cancelled = $handler->execute(['id' => 'end-2', 'config' => ['status' => 'cancelled']], [], $this->context());
        $this->assertSame('cancelled', $cancelled->metadata['end_status']);

        $failed = $handler->execute(['id' => 'end-3', 'config' => ['status' => 'failed']], [], $this->context());
        $this->assertSame('failed', $failed->metadata['end_status']);
    }

    public function test_end_handler_defaults_to_success_when_status_missing(): void
    {
        $handler = new EndNodeHandler();

        $result = $handler->execute(['id' => 'end-x', 'config' => []], [], $this->context());

        $this->assertSame('success', $result->metadata['end_status']);
    }

    public function test_end_handler_throws_for_invalid_status(): void
    {
        $handler = new EndNodeHandler();

        $this->expectException(InvalidNodeConfigException::class);
        $handler->execute(['id' => 'end-bad', 'config' => ['status' => 'unknown']], [], $this->context());
    }

    public function test_rag_query_writes_state_and_routes_success_when_found(): void
    {
        $registry = new RagAdapterRegistry();
        $registry->register(new TestRagAdapter(
            new StructuredRagResult(
                found: true,
                confidence: RagConfidence::High,
                answer: 'Office hours are 9-6.',
                intent: 'business_hours',
                metadata: ['source' => 'kb-1'],
            ),
        ));

        $handler = new RagQueryNodeHandler($registry, new TemplateRenderer());
        $result  = $handler->execute([
            'id'     => 'rag-1',
            'config' => [
                'knowledge_base_id' => 'kb-main',
                'provider'          => 'test-rag',
                'query'             => 'When do you open, {{flow.contact_name}}?',
            ],
        ], [FlowStateNamespace::FLOW => ['contact_name' => 'Alice']], $this->context(nodeId: 'rag-1'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame('Office hours are 9-6.', $result->stateChanges[FlowStateNamespace::RAG . '.answer']);
        $this->assertTrue($result->stateChanges[FlowStateNamespace::RAG . '.found']);
        $this->assertSame('high', $result->stateChanges[FlowStateNamespace::RAG . '.confidence']);
    }

    public function test_rag_query_routes_not_found_when_adapter_signals_no_match(): void
    {
        $registry = new RagAdapterRegistry();
        $registry->register(new TestRagAdapter(StructuredRagResult::notFound()));

        $handler = new RagQueryNodeHandler($registry, new TemplateRenderer());
        $result  = $handler->execute([
            'id'     => 'rag-1',
            'config' => [
                'knowledge_base_id' => 'kb-main',
                'provider'          => 'test-rag',
                'query'             => 'whatever',
            ],
        ], [], $this->context());

        $this->assertSame('not_found', $result->sourceHandle);
        $this->assertFalse($result->stateChanges[FlowStateNamespace::RAG . '.found']);
    }

    public function test_rag_query_routes_error_on_adapter_throw(): void
    {
        $registry = new RagAdapterRegistry();
        $registry->register(new ThrowingRagAdapter('upstream timeout'));

        $handler = new RagQueryNodeHandler($registry, new TemplateRenderer());
        $result  = $handler->execute([
            'id'     => 'rag-1',
            'config' => [
                'knowledge_base_id' => 'kb-main',
                'provider'          => 'throw-rag',
                'query'             => 'whatever',
            ],
        ], [], $this->context());

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('adapter_failure', $result->metadata['error_type']);
        $this->assertSame('upstream timeout', $result->metadata['error']);
    }

    public function test_rag_query_routes_error_when_provider_unknown(): void
    {
        $registry = new RagAdapterRegistry();

        $handler = new RagQueryNodeHandler($registry, new TemplateRenderer());
        $result  = $handler->execute([
            'id'     => 'rag-1',
            'config' => [
                'knowledge_base_id' => 'kb-main',
                'provider'          => 'absent',
                'query'             => 'q',
            ],
        ], [], $this->context());

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('unknown_provider', $result->metadata['error_type']);
    }

    public function test_template_renderer_falls_back_to_session_state_when_engine_is_unavailable(): void
    {
        $renderer = new TemplateRenderer();

        $resolved = $renderer->render(
            'Hi, {{flow.name}} {{flow.missing}}!',
            $this->context(),
            ['flow' => ['name' => 'Alice']],
        );

        $this->assertSame('Hi, Alice !', $resolved);
    }

    private function makeHandler(
        MessageSenderInterface $sender,
        ContentTranslatorInterface $translator,
    ): SendMessageNodeHandler {
        return new SendMessageNodeHandler(
            $sender,
            $translator,
            new TemplateRenderer(),
            Mockery::mock(InlineKeyboardEditorInterface::class),
            Mockery::mock(PersistentButtonRegistryInterface::class),
            new VariableResolver(),
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

    private function contextWithContactWriter(
        \FAPost\Foundation\Flow\Contracts\ContactWriterInterface $writer,
    ): NodeExecutionContext {
        return new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'node-1',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            contactWriter: $writer,
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

final class TestRagAdapter implements RagAdapterInterface
{
    public function __construct(private readonly StructuredRagResult $result)
    {
    }

    public function provider(): string
    {
        return 'test-rag';
    }

    public function query(string $prompt, RagQueryContext $context): StructuredRagResult
    {
        return $this->result;
    }
}

final class ThrowingRagAdapter implements RagAdapterInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function provider(): string
    {
        return 'throw-rag';
    }

    public function query(string $prompt, RagQueryContext $context): StructuredRagResult
    {
        throw new \RuntimeException($this->message);
    }
}
