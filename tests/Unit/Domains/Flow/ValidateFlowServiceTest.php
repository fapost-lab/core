<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
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
        $registry = new NodeHandlerRegistry();
        $registry->register(new RequiredConfigTestHandler());

        $service = new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
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

    private function makeServiceWithSendMessage(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry();
        $registry->register(new SendMessageStubHandler());

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
            triggerValidator: new NullTriggerConfigValidator(),
            tenantEvents: new NullTenantEventRepository(),
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
