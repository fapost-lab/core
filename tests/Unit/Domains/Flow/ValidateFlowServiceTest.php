<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use LogicException;
use Tests\TestCase;

final class ValidateFlowServiceTest extends TestCase
{
    public function test_inline_keyboard_with_default_edge_successor_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'buttons'       => [],
                    ],
                ],
                'sm_2' => [
                    'id'      => 'sm_2',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type' => 'text',
                    ],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'sm_1', 'to' => 'sm_2', 'handle' => 'default'],
            ],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('keyboard_node_must_be_terminal', $result->errors[0]->code);
        $this->assertSame('nodes.sm_1', $result->errors[0]->path);
    }

    public function test_inline_keyboard_connected_only_via_button_handles_passes_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'buttons'       => [
                            ['id' => 'btn-uuid-1234', 'label' => 'Yes', 'value' => 'yes'],
                        ],
                    ],
                ],
                'sm_2' => [
                    'id'      => 'sm_2',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type' => 'text',
                    ],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'sm_1', 'to' => 'sm_2', 'handle' => 'btn-uuid-1234'],
            ],
        );

        $this->assertTrue($result->valid);
    }

    public function test_button_edge_referencing_nonexistent_button_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'buttons'       => [], // button was removed but edge was not cleaned up
                    ],
                ],
                'sm_2' => [
                    'id'      => 'sm_2',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type' => 'text',
                    ],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'sm_1', 'to' => 'sm_2', 'handle' => 'btn-uuid-1234'],
            ],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('orphaned_button_edge', $result->errors[0]->code);
        $this->assertSame('nodes.sm_1', $result->errors[0]->path);
    }

    public function test_reply_keyboard_with_default_edge_successor_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'reply',
                        'buttons'       => [],
                    ],
                ],
                'sm_2' => [
                    'id'      => 'sm_2',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type' => 'text',
                    ],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'sm_1', 'to' => 'sm_2', 'handle' => 'default'],
            ],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('keyboard_node_must_be_terminal', $result->errors[0]->code);
    }

    public function test_keyboard_node_as_non_last_root_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        // sm_1 has a button edge to sm_2 but sm_3 is a disconnected root after sm_1
        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'reply',
                        'buttons'       => [],
                    ],
                ],
                'sm_2' => [
                    'id'      => 'sm_2',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type' => 'text',
                    ],
                ],
            ],
            edges: [],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('keyboard_node_must_be_terminal', $result->errors[0]->code);
    }

    public function test_validate_reports_missing_required_config_field_from_handler_schema(): void
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(RequiredConfigTestHandler::class);

        $service = new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );

        $result = $service->execute([
            'send_1' => [
                'id'      => 'send_1',
                'type'    => 'required_config_test',
                'version' => 1,
                'config'  => [],
            ],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('missing_config_field', $result->errors[0]->code);
        $this->assertSame('nodes.send_1.config.body', $result->errors[0]->path);
    }

    public function test_number_type_with_valid_button_value_passes(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'save_to_type'  => 'number',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Option 1', 'value' => '42'],
                            ['id' => 'b2', 'label' => 'Option 2', 'value' => '3.14'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertTrue($result->valid);
    }

    public function test_number_type_with_non_numeric_button_value_fails(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'save_to_type'  => 'number',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Yes', 'value' => 'yes'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('button_value_type_mismatch', $result->errors[0]->code);
    }

    public function test_boolean_type_with_invalid_button_value_fails(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'save_to_type'  => 'boolean',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Yes', 'value' => 'yes'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('button_value_type_mismatch', $result->errors[0]->code);
    }

    public function test_boolean_type_with_valid_button_values_passes(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'save_to_type'  => 'boolean',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Yes', 'value' => 'true'],
                            ['id' => 'b2', 'label' => 'No', 'value' => 'false'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertTrue($result->valid);
    }

    public function test_reply_keyboard_ignores_value_type_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'reply',
                        'save_to_type'  => 'number',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Option A', 'value' => 'not-a-number'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertTrue($result->valid);
    }

    public function test_empty_button_values_skip_type_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute(
            nodes: [
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => [
                        'content_type'  => 'text_with_keyboard',
                        'keyboard_mode' => 'inline',
                        'save_to_type'  => 'number',
                        'buttons'       => [
                            ['id' => 'b1', 'label' => 'Option A', 'value' => ''],
                        ],
                    ],
                ],
            ],
        );

        $this->assertTrue($result->valid);
    }

    public function test_send_message_with_legacy_media_url_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute([
            'n1' => [
                'id'      => 'n1',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type' => 'image',
                    'media_url'    => 'https://example.com/img.jpg',
                ],
            ],
        ]);

        $this->assertFalse($result->valid);
        $codes = array_column(
            array_map(fn ($e) => ['code' => $e->code], $result->errors),
            'code',
        );
        $this->assertContains('legacy_media_field', $codes);
    }

    public function test_send_message_with_legacy_media_path_fails_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        $result = $service->execute([
            'n1' => [
                'id'      => 'n1',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type' => 'document',
                    'media_path'   => '/storage/file.pdf',
                ],
            ],
        ]);

        $this->assertFalse($result->valid);
        $codes = array_column(
            array_map(fn ($e) => ['code' => $e->code], $result->errors),
            'code',
        );
        $this->assertContains('legacy_media_field', $codes);
    }

    public function test_emit_event_rejects_missing_event_type(): void
    {
        $service = $this->makeServiceWithEmitEvent();

        $result = $service->execute([
            'ev_1' => [
                'id'      => 'ev_1',
                'type'    => 'emit_event',
                'version' => 1,
                'config'  => ['event_type' => '   '],
            ],
        ]);

        $this->assertFalse($result->valid);
        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('emit_event_missing_type', $codes);
    }

    public function test_emit_event_rejects_template_in_event_type(): void
    {
        $service = $this->makeServiceWithEmitEvent();

        $result = $service->execute([
            'ev_1' => [
                'id'      => 'ev_1',
                'type'    => 'emit_event',
                'version' => 1,
                'config'  => ['event_type' => 'sales.{{flow.kind}}'],
            ],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('emit_event_template_in_type', $codes);
    }

    public function test_emit_event_rejects_invalid_characters_in_event_type(): void
    {
        $service = $this->makeServiceWithEmitEvent();

        $result = $service->execute([
            'ev_1' => [
                'id'      => 'ev_1',
                'type'    => 'emit_event',
                'version' => 1,
                'config'  => ['event_type' => 'sales/order/created'],
            ],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('emit_event_invalid_type', $codes);
    }

    public function test_emit_event_accepts_dotted_alphanumeric_type(): void
    {
        $service = $this->makeServiceWithEmitEvent();

        $result = $service->execute([
            'ev_1' => [
                'id'      => 'ev_1',
                'type'    => 'emit_event',
                'version' => 1,
                'config'  => ['event_type' => 'sales.order.created', 'payload' => []],
            ],
        ]);

        $this->assertTrue($result->valid, implode(', ', array_map(static fn ($e) => $e->code, $result->errors)));
    }

    public function test_end_node_with_invalid_status_fails_validation(): void
    {
        $service = $this->makeServiceWithEnd();

        $result = $service->execute([
            'end_1' => ['id' => 'end_1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'unknown']],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('end_invalid_status', $codes);
    }

    public function test_end_node_with_outgoing_edge_fails_validation(): void
    {
        $service = $this->makeServiceWithEnd();

        $result = $service->execute(
            nodes: [
                'end_1' => ['id' => 'end_1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                'tail'  => ['id' => 'tail',  'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e', 'from' => 'end_1', 'to' => 'tail', 'handle' => 'default'],
            ],
        );

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('end_node_has_outgoing_edge', $codes);
    }

    public function test_end_node_terminal_passes_validation(): void
    {
        $service = $this->makeServiceWithEnd();

        $result = $service->execute([
            'end_1' => ['id' => 'end_1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->assertTrue($result->valid, implode(', ', array_map(static fn ($e) => $e->code, $result->errors)));
    }

    public function test_subflow_node_with_missing_flow_id_fails_validation(): void
    {
        $service = $this->makeServiceWithSubflow();

        $result = $service->execute([
            'sf_1' => ['id' => 'sf_1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => '   ']],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('subflow_missing_flow_id', $codes);
    }

    public function test_subflow_node_rejects_template_in_flow_id(): void
    {
        $service = $this->makeServiceWithSubflow();

        $result = $service->execute([
            'sf_1' => ['id' => 'sf_1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'flow-{{flow.target}}']],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('subflow_template_in_flow_id', $codes);
    }

    public function test_subflow_node_rejects_invalid_timeout(): void
    {
        $service = $this->makeServiceWithSubflow();

        $result = $service->execute([
            'sf_1' => ['id' => 'sf_1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'child-flow', 'timeout' => 'not-iso']],
        ]);

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('subflow_invalid_timeout', $codes);
    }

    public function test_subflow_node_passes_with_valid_config(): void
    {
        $service = $this->makeServiceWithSubflow();

        $result = $service->execute([
            'sf_1' => ['id' => 'sf_1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'child-flow', 'timeout' => 'PT24H']],
        ]);

        $this->assertTrue($result->valid, implode(', ', array_map(static fn ($e) => $e->code, $result->errors)));
    }

    public function test_loop_without_reachable_loop_end_fails_validation(): void
    {
        $service = $this->makeServiceWithLoop();

        $result = $service->execute(
            nodes: [
                'loop_1' => [
                    'id'      => 'loop_1',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => ['mode' => 'while'],
                ],
                'body_1' => [
                    'id'      => 'body_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => ['content_type' => 'text'],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'loop_1', 'to' => 'body_1', 'handle' => 'loop'],
            ],
        );

        $this->assertFalse($result->valid);
        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('loop_missing_loop_end', $codes);
    }

    public function test_loop_with_reachable_loop_end_passes_reachability(): void
    {
        $service = $this->makeServiceWithLoop();

        $result = $service->execute(
            nodes: [
                'loop_1' => [
                    'id'      => 'loop_1',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => ['mode' => 'while'],
                ],
                'body_1' => [
                    'id'      => 'body_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => ['content_type' => 'text'],
                ],
                'end_1' => [
                    'id'      => 'end_1',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => ['loop_node_id' => 'loop_1'],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'loop_1', 'to' => 'body_1', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'body_1', 'to' => 'end_1', 'handle' => 'default'],
            ],
        );

        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertNotContains('loop_missing_loop_end', $codes);
    }

    public function test_loop_end_paired_with_another_loop_does_not_satisfy_reachability(): void
    {
        $service = $this->makeServiceWithLoop();

        // loop_1's body reaches a loop_end, but that loop_end is paired with a
        // different loop — it must not count as loop_1's terminator.
        $result = $service->execute(
            nodes: [
                'loop_1' => [
                    'id'      => 'loop_1',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => ['mode' => 'while'],
                ],
                'body_1' => [
                    'id'      => 'body_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => ['content_type' => 'text'],
                ],
                'end_other' => [
                    'id'      => 'end_other',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => ['loop_node_id' => 'loop_other'],
                ],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'loop_1', 'to' => 'body_1', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'body_1', 'to' => 'end_other', 'handle' => 'default'],
            ],
        );

        $this->assertFalse($result->valid);
        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains('loop_missing_loop_end', $codes);
    }

    public function test_comment_annotation_node_is_exempt_from_validation(): void
    {
        $service = $this->makeServiceWithSendMessage();

        // A comment node has no handler; it must not be flagged as an unknown type.
        $result = $service->execute(
            nodes: [
                'note_1' => [
                    'id'      => 'note_1',
                    'type'    => 'comment',
                    'version' => 1,
                    'config'  => ['text' => 'Remember to localize this branch'],
                ],
                'sm_1' => [
                    'id'      => 'sm_1',
                    'type'    => 'send_message',
                    'version' => 1,
                    'config'  => ['content_type' => 'text'],
                ],
            ],
        );

        $this->assertTrue($result->valid, 'Comment nodes must not produce validation errors.');
    }

    private function makeServiceWithEmitEvent(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(EmitEventStubHandler::class);

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );
    }

    private function makeServiceWithLoop(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(LoopStubHandler::class);
        $registry->register(LoopEndStubHandler::class);
        $registry->register(SendMessageStubHandler::class);

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );
    }

    private function makeServiceWithSubflow(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(SubflowStubHandler::class);

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );
    }

    private function makeServiceWithEnd(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(EndStubHandler::class);

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );
    }

    private function makeServiceWithSendMessage(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(SendMessageStubHandler::class);

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
            tenantContext: new NullTenantContext(),
        );
    }
}

final class RequiredConfigTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'required_config_test';
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
        return 'Required Config Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [
            'required' => ['body'],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class NullDataAccessorRegistry implements DataAccessorRegistryInterface
{
    public function has(string $namespacePrefix): bool
    {
        return false;
    }

    public function resolve(string $namespacePrefix): DataAccessorInterface
    {
        throw new LogicException('Not used in this test.');
    }
}

final class NullTriggerConfigValidator implements FlowTriggerConfigValidatorInterface
{
    public function validate(string $type, array $config): void
    {
    }
}

final class NullTenantEventRepository implements TenantEventRepositoryInterface
{
    public function getEventNamesByTenant(string $tenantId): array
    {
        return [];
    }

    public function registerEventNames(string $tenantId, array $eventNames): void
    {
    }
}

final class NullTenantContext implements TenantContextInterface
{
    public function set(TenantInterface $tenant): void
    {
    }

    public function get(): TenantInterface
    {
        throw new LogicException('Tenant context not set in unit test.');
    }

    public function isResolved(): bool
    {
        return false;
    }

    public function reset(): void
    {
    }
}

final class SendMessageStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'send_message';
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
        return 'Send Message';
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
        return NodeExecutionResult::executed();
    }
}

final class EmitEventStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'emit_event';
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
        return 'Emit Event';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class EndStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'end';
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
        return 'End';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class LoopStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'loop';
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
        return 'Loop';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class LoopEndStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'loop_end';
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
        return 'Loop End';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class SubflowStubHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'subflow';
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
        return 'Subflow';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
