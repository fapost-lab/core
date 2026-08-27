<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Services;

use App\Domains\Flow\Services\VariableSchemaCollector;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\State\Variables\VariableType;
use Tests\TestCase;

final class VariableSchemaCollectorTest extends TestCase
{
    private VariableSchemaCollector $collector;

    public function test_collects_input_node_variable(): void
    {
        $nodes = [
            [
                'id'     => 'node-1',
                'type'   => 'input',
                'config' => [
                    'variable' => [
                        'name'    => 'phone_number',
                        'storage' => 'session',
                        'type'    => 'phone',
                    ],
                ],
            ],
        ];

        $result = $this->collector->collect($nodes);

        $this->assertCount(1, $result);
        $this->assertSame('phone_number', $result[0]['variable']->name);
        $this->assertSame(VariableStorage::Session, $result[0]['variable']->storage);
        $this->assertSame(VariableType::Phone, $result[0]['variable']->type);
        $this->assertSame('node-1', $result[0]['node_id']);
    }

    public function test_collects_assign_node_operations(): void
    {
        $nodes = [
            [
                'id'     => 'node-2',
                'type'   => 'assign',
                'config' => [
                    'operations' => [
                        [
                            'variable' => [
                                'name'    => 'score',
                                'storage' => 'contact',
                                'group'   => 'profile',
                                'type'    => 'number',
                            ],
                            'value'    => '42',
                        ],
                        [
                            'variable' => [
                                'name'    => 'confirmed',
                                'storage' => 'session',
                                'type'    => 'confirm',
                            ],
                            'value'    => 'yes',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->collector->collect($nodes);

        $this->assertCount(2, $result);

        $this->assertSame('score', $result[0]['variable']->name);
        $this->assertSame(VariableStorage::Contact, $result[0]['variable']->storage);
        $this->assertSame('profile', $result[0]['variable']->group);
        $this->assertSame(VariableType::Number, $result[0]['variable']->type);

        $this->assertSame('confirmed', $result[1]['variable']->name);
        $this->assertSame(VariableType::Confirm, $result[1]['variable']->type);
    }

    public function test_collects_send_message_save_to_variable(): void
    {
        $nodes = [
            [
                'id'     => 'node-3',
                'type'   => 'send_message',
                'config' => [
                    'save_to_variable' => [
                        'name'    => 'menu_choice',
                        'storage' => 'session',
                        'type'    => 'select',
                    ],
                ],
            ],
        ];

        $result = $this->collector->collect($nodes);

        $this->assertCount(1, $result);
        $this->assertSame('menu_choice', $result[0]['variable']->name);
        $this->assertSame(VariableType::Select, $result[0]['variable']->type);
    }

    public function test_falls_back_to_text_when_type_absent(): void
    {
        $nodes = [
            [
                'id'     => 'node-4',
                'type'   => 'input',
                'config' => [
                    'variable' => [
                        'name'    => 'answer',
                        'storage' => 'session',
                        // no type
                    ],
                ],
            ],
        ];

        $result = $this->collector->collect($nodes);

        $this->assertCount(1, $result);
        $this->assertSame(VariableType::Text, $result[0]['variable']->type);
    }

    public function test_skips_nodes_without_id(): void
    {
        $nodes = [
            [
                'type'   => 'input',
                'config' => [
                    'variable' => ['name' => 'x', 'storage' => 'session'],
                ],
            ],
        ];

        $this->assertCount(0, $this->collector->collect($nodes));
    }

    public function test_skips_unknown_node_types(): void
    {
        $nodes = [
            [
                'id'     => 'node-5',
                'type'   => 'end',
                'config' => [],
            ],
        ];

        $this->assertCount(0, $this->collector->collect($nodes));
    }

    public function test_skips_malformed_variable_configs_gracefully(): void
    {
        $nodes = [
            [
                'id'     => 'node-6',
                'type'   => 'input',
                'config' => [
                    'variable' => 'not-an-array',
                ],
            ],
        ];

        $this->assertCount(0, $this->collector->collect($nodes));
    }

    public function test_skips_invalid_identifier_without_throwing(): void
    {
        $nodes = [
            [
                'id'     => 'node-7',
                'type'   => 'input',
                'config' => [
                    'variable' => [
                        'name'    => '1invalid',
                        'storage' => 'session',
                    ],
                ],
            ],
        ];

        // Should skip gracefully — invalid identifier throws InvalidArgumentException
        // which is caught inside the collector.
        $this->assertCount(0, $this->collector->collect($nodes));
    }

    public function test_collects_from_multiple_node_types_in_one_flow(): void
    {
        $nodes = [
            [
                'id'     => 'n1',
                'type'   => 'input',
                'config' => [
                    'variable' => ['name' => 'name', 'storage' => 'session', 'type' => 'text'],
                ],
            ],
            [
                'id'     => 'n2',
                'type'   => 'assign',
                'config' => [
                    'operations' => [
                        [
                            'variable' => ['name' => 'age', 'storage' => 'contact', 'type' => 'number'],
                            'value'    => '25',
                        ],
                    ],
                ],
            ],
            [
                'id'     => 'n3',
                'type'   => 'send_message',
                'config' => [
                    'save_to_variable' => ['name' => 'choice', 'storage' => 'session', 'type' => 'select'],
                ],
            ],
        ];

        $result = $this->collector->collect($nodes);

        $this->assertCount(3, $result);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new VariableSchemaCollector();
    }
}
