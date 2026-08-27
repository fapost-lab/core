<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
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
use FAPost\Foundation\Flow\Call\CallContext;
use FAPost\Foundation\Flow\Call\CallRequest;
use FAPost\Foundation\Flow\Call\CallResult;
use FAPost\Foundation\Flow\Call\CallTransportInterface;
use FAPost\Foundation\Flow\Enums\StateNamespace;
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
            Mockery::mock(MessageSenderInterface::class),
            Mockery::mock(ContentTranslatorInterface::class),
            new \App\Domains\Flow\Validation\InputValidator(),
        );
        $node    = ['id' => 'input-1', 'config' => ['save_to' => StateNamespace::Flow->value . '.user_name']];

        $waiting  = $handler->execute($node, [], $this->context(incoming: null));
        $executed = $handler->execute($node, [], $this->context(incoming: $this->incoming('John')));

        $this->assertSame(NodeExecutionStatus::Waiting, $waiting->status);
        $this->assertSame(NodeExecutionStatus::Executed, $executed->status);
        $this->assertSame('John', $executed->stateChanges[StateNamespace::Flow->value . '.user_name']);
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
                'check' => StateNamespace::Flow->value . '.segment',
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
                'check' => StateNamespace::Flow->value . '.optional',
                'rules' => [['operator' => 'empty', 'handle' => 'empty']],
            ],
        ];

        $flow   = $handler->execute($flowNode, [StateNamespace::Flow->value => ['segment' => 'vip']], $this->context());
        $module = $handler->execute($moduleNode, [], $this->context());
        $empty  = $handler->execute($emptyNode, [StateNamespace::Flow->value => ['optional' => '']], $this->context());

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
            [StateNamespace::Flow->value => ['code' => '1234']],
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
            [StateNamespace::Rag->value => ['found' => true]],
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
                'check' => StateNamespace::Flow->value . '.code',
                'rules' => [
                    ['operator' => 'eq', 'value' => '1234', 'handle' => 'yes'],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            [StateNamespace::Flow->value => ['code' => '1234']],
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
            StateNamespace::System->value => ['delay' => ['delay-1' => ['scheduled_at' => '2026-01-01T00:00:00+00:00']]],
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
        ], [StateNamespace::Flow->value => ['name' => 'Jane']], $contextWithWriter);

        $flow = $handler->execute([
            'id'     => 'set-flow',
            'config' => ['target' => 'flow', 'key' => 'nickname', 'value' => '{{flow.name}}'],
        ], [StateNamespace::Flow->value => ['name' => 'Jane']], $this->context());

        $this->assertSame('Jane', $flow->stateChanges[StateNamespace::Flow->value . '.nickname']);
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
            Mockery::mock(MessageSenderInterface::class),
            Mockery::mock(ContentTranslatorInterface::class),
            new \App\Domains\Flow\Validation\InputValidator(),
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
            Mockery::mock(MessageSenderInterface::class),
            Mockery::mock(ContentTranslatorInterface::class),
            new \App\Domains\Flow\Validation\InputValidator(),
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
            Mockery::mock(MessageSenderInterface::class),
            Mockery::mock(ContentTranslatorInterface::class),
            new \App\Domains\Flow\Validation\InputValidator(),
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

    public function test_input_number_validation_stores_parsed_float(): void
    {
        $handler = $this->makeInputHandler();

        $node = [
            'id'     => 'input-num',
            'config' => [
                'expected_type' => 'number',
                'variable'      => ['name' => 'age', 'storage' => 'session'],
                'validation'    => ['min' => 0, 'max' => 150],
            ],
        ];

        $result = $handler->execute($node, [], $this->context(incoming: $this->incoming('42')));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame(42.0, $result->stateChanges['flow.age']);
    }

    public function test_input_invalid_increments_retry_and_re_parks(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        // The on_invalid_message hint goes out on each failed attempt.
        $sender->shouldReceive('send')->once()->andReturn('ext-msg-1');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->andReturnUsing(
            static fn (mixed $field): string => is_array($field) ? (string)reset($field) : (string)$field,
        );

        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
            $sender,
            $translator,
            new \App\Domains\Flow\Validation\InputValidator(),
        );

        $node = [
            'id'     => 'input-retry',
            'config' => [
                'expected_type'      => 'email',
                'variable'           => ['name' => 'email', 'storage' => 'session'],
                'retry_limit'        => 2,
                'on_invalid_message' => 'try again',
            ],
        ];

        $result = $handler->execute(
            $node,
            [],
            $this->context(incoming: $this->incoming('not-an-email'), nodeId: 'input-retry'),
        );

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame(1, $result->stateChanges['system.input.input-retry.retry_count']);
        $this->assertSame('invalid_email', $result->metadata['error_key']);
    }

    public function test_input_routes_to_invalid_handle_after_retry_limit(): void
    {
        $handler = $this->makeInputHandler();

        $node = [
            'id'     => 'input-exhausted',
            'config' => [
                'expected_type' => 'number',
                'variable'      => ['name' => 'n', 'storage' => 'session'],
                'retry_limit'   => 2,
            ],
        ];

        $state = ['system' => ['input' => ['input-exhausted' => ['retry_count' => 2]]]];

        $result = $handler->execute(
            $node,
            $state,
            $this->context(incoming: $this->incoming('still not a number'), nodeId: 'input-exhausted'),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('invalid', $result->sourceHandle);
        $this->assertNull($result->stateChanges['system.input.input-exhausted.retry_count']);
    }

    public function test_input_select_resolves_pressed_button_value(): void
    {
        // CallbackDataCodec only round-trips real UUIDs — the encoding strips
        // dashes and unpacks two 32-char hex halves on decode.
        $sessionId = '550e8400-e29b-41d4-a716-446655440000';
        $buttonId  = '11111111-1111-4111-8111-111111111111';
        $payload   = \App\Domains\Flow\Support\CallbackDataCodec::encode($sessionId, $buttonId);

        $handler = $this->makeInputHandler();

        $node = [
            'id'     => 'input-select',
            'config' => [
                'expected_type' => 'select',
                'variable'      => ['name' => 'choice', 'storage' => 'session'],
                'buttons'       => [
                    ['id' => $buttonId, 'value' => 'option_a', 'label' => 'A'],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'value' => 'option_b', 'label' => 'B'],
                ],
            ],
        ];

        $incoming = new IncomingMessage('upd-2', 'ext-user', 'ext-chat', $payload, IncomingMessageType::CallbackQuery, 'telegram');

        // Prompt was already sent on the previous pass — simulate by pre-populating SENT_MESSAGES.
        $state = ['system' => ['sent_messages' => ['input-select' => 'ext-msg-prompt']]];

        $context = new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: $sessionId,
            nodeId: 'input-select',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            incoming: $incoming,
        );

        $result = $handler->execute($node, $state, $context);

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('option_a', $result->stateChanges['flow.choice']);
    }

    private function makeInputHandler(): InputNodeHandler
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->zeroOrMoreTimes()->andReturn('ext-msg');

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->andReturnUsing(
            static fn (mixed $field): string => is_array($field) ? (string)reset($field) : (string)$field,
        );

        return new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
            $sender,
            $translator,
            new \App\Domains\Flow\Validation\InputValidator(),
        );
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
            [StateNamespace::Flow->value => ['name' => 'Jane']],
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

    public function test_call_renders_templates_saves_response_and_maps_fields(): void
    {
        $transport = new FakeCallTransport(
            'http',
            CallResult::ok(['data' => ['id' => 42]], ['status_code' => 201, 'headers' => ['X-Trace' => 'abc']]),
        );
        $handler = $this->makeCallHandler($transport);

        $node = [
            'id'     => 'call-1',
            'config' => [
                'transport'         => 'http',
                'target'            => 'POST https://api.example.com/users/{{flow.uid}}',
                'parameters'        => ['body.name' => '{{flow.name}}'],
                'transport_options' => ['timeout' => 15, 'success_when' => '2xx'],
                'save_to_variable'  => ['name' => 'response', 'storage' => 'session'],
                'result_mapping'    => [
                    ['from' => 'body.data.id',     'to' => ['name' => 'user_id', 'storage' => 'session']],
                    ['from' => 'status',           'to' => ['name' => 'http_status', 'storage' => 'session']],
                    ['from' => 'body.missing.path', 'to' => ['name' => 'ghost', 'storage' => 'session']],
                ],
            ],
        ];
        $state = ['flow' => ['uid' => 'u7', 'name' => 'Bob']];

        $result = $handler->execute($node, $state, $this->context(nodeId: 'call-1'));

        // Templates rendered into the outgoing request.
        $this->assertSame('POST https://api.example.com/users/u7', $transport->lastRequest?->target);
        $this->assertSame('Bob', $transport->lastRequest?->parameters['body.name']);

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('success', $result->sourceHandle);

        // Whole-response object saved as {status, body, headers}.
        $this->assertSame(
            ['status' => 201, 'body' => ['data' => ['id' => 42]], 'headers' => ['X-Trace' => 'abc']],
            $result->stateChanges['flow.response'],
        );

        // Field mapping extracts individual paths; missing path resolves to null.
        $this->assertSame(42, $result->stateChanges['flow.user_id']);
        $this->assertSame(201, $result->stateChanges['flow.http_status']);
        $this->assertArrayHasKey('flow.ghost', $result->stateChanges);
        $this->assertNull($result->stateChanges['flow.ghost']);
    }

    public function test_call_routes_transport_failure_to_error_handle(): void
    {
        $transport = new FakeCallTransport('http', CallResult::error('transport_failure', null, ['exception' => 'timeout']));
        $handler   = $this->makeCallHandler($transport);

        $result = $handler->execute([
            'id'     => 'call-x',
            'config' => ['transport' => 'http', 'target' => 'GET https://x.test'],
        ], [], $this->context(nodeId: 'call-x'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('transport_failure', $result->metadata['error_code']);
    }

    public function test_call_unknown_transport_routes_to_error(): void
    {
        $handler = new CallNodeHandler(new CallTransportRegistry(), new TemplateRenderer(), new VariableResolver());

        $result = $handler->execute([
            'id'     => 'call-u',
            'config' => ['transport' => 'nope', 'target' => 'GET https://x'],
        ], [], $this->context(nodeId: 'call-u'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('unknown_transport', $result->metadata['error_type']);
    }

    public function test_call_legacy_config_posts_url_and_saves_body(): void
    {
        $transport = new FakeCallTransport('http', CallResult::ok(['ok' => true], ['status_code' => 200]));
        $handler   = $this->makeCallHandler($transport);

        $result = $handler->execute([
            'id'     => 'hook-legacy',
            'config' => ['url' => 'https://example.test/hook', 'save_response_to' => 'flow.webhook'],
        ], [], $this->context(nodeId: 'hook-legacy'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame(['ok' => true], $result->stateChanges['flow.webhook']);
        // Legacy routes through the http transport with a synthesized target.
        $this->assertSame('POST https://example.test/hook', $transport->lastRequest?->target);
    }

    private function makeCallHandler(CallTransportInterface $transport): CallNodeHandler
    {
        $registry = new CallTransportRegistry();
        $registry->register($transport);

        return new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver());
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
            StateNamespace::Flow->value => ['order_id' => 'ORD-7', 'amount' => 199],
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
        ], [StateNamespace::Flow->value => ['contact_name' => 'Alice']], $this->context(nodeId: 'rag-1'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame('Office hours are 9-6.', $result->stateChanges[StateNamespace::Rag->value . '.answer']);
        $this->assertTrue($result->stateChanges[StateNamespace::Rag->value . '.found']);
        $this->assertSame('high', $result->stateChanges[StateNamespace::Rag->value . '.confidence']);
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
        $this->assertFalse($result->stateChanges[StateNamespace::Rag->value . '.found']);
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

    public function test_send_message_returns_error_handle_when_sender_throws(): void
    {
        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')->once()->andThrow(new \RuntimeException('connection refused'));

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->andReturn('hello');

        $handler = $this->makeHandler($sender, $translator);
        $result  = $handler->execute([
            'id'     => 'node-err',
            'config' => ['content_type' => 'text', 'text' => ['en' => 'hello']],
        ], [], $this->context(nodeId: 'node-err'));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('send_failure', $result->metadata['error_type']);
        $this->assertArrayNotHasKey(SystemStateKeys::SENT_MESSAGES, $result->stateChanges);
    }

    public function test_send_message_hint_uses_catalog_key_when_user_types_during_button_wait(): void
    {
        $sessionUuid = '00000000-0000-4000-8000-000000000050';
        $buttonUuid  = '11111111-1111-4111-8111-111111111150';

        $hintText = '⚠ localized hint';

        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (string $t, string $c, string $s, array $payload) use ($hintText): string {
                $this->assertSame($hintText, $payload['text']);

                return 'hint-sent';
            });

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->andReturn('Choose');
        $translator->shouldReceive('translate')
            ->with('errors.waiting_for_button', 'en')
            ->once()
            ->andReturn($hintText);

        $handler = $this->makeHandler($sender, $translator);
        $node    = [
            'id'     => 'node-hint',
            'config' => [
                'content_type'                => 'text_with_keyboard',
                'keyboard_mode'               => 'inline',
                'remove_keyboard_after_press' => false,
                'text'                        => ['en' => 'Choose'],
                'buttons'                     => [
                    ['id' => $buttonUuid, 'label' => ['en' => 'Yes'], 'value' => 'yes', 'row' => 0, 'order' => 0],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            ['system' => ['sent_messages' => ['node-hint' => 'ext-inline']]],
            $this->context(
                incoming: new IncomingMessage(
                    updateId: 'txt-1',
                    externalUserId: 'ext-user',
                    externalChatId: 'ext-chat',
                    text: 'random text from user',
                    type: IncomingMessageType::Text,
                    platform: 'telegram',
                ),
                nodeId: 'node-hint',
                sessionId: $sessionUuid,
            ),
        );

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
    }

    public function test_send_message_dynamic_keyboard_generates_buttons_and_persists_them(): void
    {
        $sessionUuid = '00000000-0000-4000-8000-000000000060';
        $nodeId      = 'node-dyn';

        // Items must follow the DynamicKeyboardItem contract: required `label` field.
        $employees = [
            ['label' => 'Alice', 'id' => 'emp-1', 'dept' => 'Engineering'],
            ['label' => 'Bob',   'id' => 'emp-2', 'dept' => 'Sales'],
            ['label' => 'Carol', 'id' => 'emp-3', 'dept' => 'HR'],
        ];

        $capturedPayload = null;

        $sender = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldReceive('send')
            ->once()
            ->andReturnUsing(function ($t, $c, $s, array $payload) use (&$capturedPayload): string {
                $capturedPayload = $payload;

                return 'ext-dynamic';
            });

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')
            ->andReturnUsing(static function (array|string $content, string $lang): string {
                return is_array($content) ? ($content[$lang] ?? $content['en'] ?? '') : $content;
            });

        $handler = $this->makeHandler($sender, $translator);

        $node = [
            'id'     => $nodeId,
            'config' => [
                'content_type'    => 'text_with_keyboard',
                'keyboard_mode'   => 'inline',
                'text'            => ['en' => 'Choose an employee'],
                'dynamic_buttons' => [
                    'source'       => 'flow.employees',
                    'max_per_row'  => 2,
                    'save_item_to' => ['name' => 'selected_employee', 'storage' => 'session', 'group' => null],
                ],
            ],
        ];

        $state  = [StateNamespace::Flow->value => ['employees' => $employees]];
        $result = $handler->execute($node, $state, $this->context(nodeId: $nodeId, sessionId: $sessionUuid));

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertIsArray($capturedPayload);
        $this->assertCount(3, $capturedPayload['buttons']);
        $this->assertSame('Alice', $capturedPayload['buttons'][0]['label']);
        $this->assertArrayNotHasKey('value', $capturedPayload['buttons'][0]);

        $persisted = $result->stateChanges[SystemStateKeys::SEND_MESSAGE_DYNAMIC_BUTTONS . ".{$nodeId}"] ?? null;
        $this->assertIsArray($persisted);
        $this->assertCount(3, $persisted);
        // Full item is stored alongside the button for save_item_to on press.
        $this->assertSame($employees[0], $persisted[0]['item']);
    }

    public function test_send_message_dynamic_keyboard_resume_routes_to_default_handle(): void
    {
        $sessionUuid = '00000000-0000-4000-8000-000000000061';
        $nodeId      = 'node-dyn-resume';

        $buttonId = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_OID, "{$nodeId}:0")->toString();
        $cbData   = CallbackDataCodec::encode($sessionUuid, $buttonId);

        $sender     = Mockery::mock(MessageSenderInterface::class);
        $sender->shouldNotReceive('send');
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

        $persistedButtons = [
            [
                'id'    => $buttonId,
                'label' => 'Alice',
                'row'   => 0,
                'item'  => ['label' => 'Alice', 'id' => 'emp-1', 'dept' => 'Engineering'],
            ],
        ];

        $state = [
            'system' => [
                'sent_messages' => [$nodeId => 'ext-dyn'],
                'send_message'  => [
                    'dynamic_buttons' => [$nodeId => $persistedButtons],
                ],
            ],
        ];

        $node = [
            'id'     => $nodeId,
            'config' => [
                'content_type'    => 'text_with_keyboard',
                'keyboard_mode'   => 'inline',
                'text'            => ['en' => 'Choose'],
                'dynamic_buttons' => [
                    'source'       => 'flow.employees',
                    'save_item_to' => ['name' => 'selected_employee', 'storage' => 'session', 'group' => null],
                ],
            ],
        ];

        $result = $handler->execute(
            $node,
            $state,
            $this->context(
                incoming: new IncomingMessage(
                    updateId: 'cb-dyn',
                    externalUserId: 'u',
                    externalChatId: 'c',
                    text: $cbData,
                    type: IncomingMessageType::CallbackQuery,
                    platform: 'telegram',
                ),
                nodeId: $nodeId,
                sessionId: $sessionUuid,
            ),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        // Full item object must be saved to save_item_to path on press.
        $this->assertSame(
            ['label' => 'Alice', 'id' => 'emp-1', 'dept' => 'Engineering'],
            $result->stateChanges['flow.selected_employee'] ?? null,
        );
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

final class FakeCallTransport implements CallTransportInterface
{
    public ?CallRequest $lastRequest = null;

    public function __construct(
        private readonly string $id,
        private readonly CallResult $result,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function execute(CallRequest $request, CallContext $context): CallResult
    {
        $this->lastRequest = $request;

        return $this->result;
    }
}
