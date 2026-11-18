<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Validation\LoopConfigChecker;
use PHPUnit\Framework\TestCase;

final class LoopConfigCheckerTest extends TestCase
{
    public function test_a_session_variable_named_like_the_default_iterator_is_an_error_naming_the_loop(): void
    {
        $result = $this->check(
            nodes: [
                $this->loop('loop_1', ['mode' => 'counted', 'count_source' => $this->literal(3)]),
                $this->node('set', 'assign', ['operations' => [[
                    'variable' => ['name' => 'iterator', 'storage' => 'session'],
                    'value'    => '1',
                ]]]),
            ],
        );

        $this->assertSame(['loop_iterator_variable_conflict'], $this->codes($result['errors']));
        $this->assertSame('nodes.loop_1.config.iterator_name', $result['errors'][0]->path);
        $this->assertStringContainsString("'iterator'", $result['errors'][0]->message);
        $this->assertStringContainsString("'set'", $result['errors'][0]->message);
    }

    public function test_the_total_variable_and_a_custom_iterator_name_are_checked_too(): void
    {
        $result = $this->check(
            nodes: [
                $this->loop('loop_1', ['count_source' => $this->literal(3), 'iterator_name' => 'photo']),
                $this->node('ask', 'input', ['variable' => ['name' => 'photo_total', 'storage' => 'session', 'type' => 'number']]),
                $this->node('legacy', 'assign', ['target' => 'flow', 'key' => 'photo', 'value' => 'x']),
            ],
        );

        $this->assertSame(['loop_iterator_variable_conflict', 'loop_iterator_variable_conflict'], $this->codes($result['errors']));
    }

    public function test_a_contact_variable_with_the_iterator_name_is_not_a_conflict(): void
    {
        $result = $this->check(
            nodes: [
                $this->loop('loop_1', ['count_source' => $this->literal(3)]),
                $this->node('set', 'assign', ['operations' => [[
                    'variable' => ['name' => 'iterator', 'storage' => 'contact'],
                    'value'    => '1',
                ]]]),
            ],
        );

        $this->assertSame([], $result['errors']);
    }

    public function test_a_non_numeric_literal_count_is_an_error_and_a_numeric_string_is_not(): void
    {
        $bad  = $this->check(nodes: [$this->loop('loop_1', ['count_source' => $this->literal('three')])]);
        $none = $this->check(nodes: [$this->loop('loop_1', ['count_source' => ['type' => 'literal']])]);
        $good = $this->check(nodes: [$this->loop('loop_1', ['count_source' => $this->literal('5')])]);

        $this->assertSame(['loop_count_not_numeric'], $this->codes($bad['errors']));
        $this->assertSame('nodes.loop_1.config.count_source', $bad['errors'][0]->path);
        $this->assertSame(['loop_count_not_numeric'], $this->codes($none['errors']));
        $this->assertSame([], $good['errors']);
    }

    public function test_a_variable_count_source_is_not_checked_statically(): void
    {
        $result = $this->check(nodes: [$this->loop('loop_1', [
            'count_source' => ['ref' => 'user_variable', 'variable' => ['name' => 'n', 'storage' => 'session']],
        ])]);

        $this->assertSame([], $result['errors']);
    }

    public function test_a_while_condition_nothing_in_the_body_changes_is_a_warning(): void
    {
        $result = $this->check(
            nodes: [
                $this->whileLoop('loop_1', 'done'),
                $this->node('say', 'send_message'),
                $this->loopEnd('end_1', 'loop_1'),
            ],
            edges: [$this->edge('loop_1', 'say', 'loop'), $this->edge('say', 'end_1')],
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame(['loop_while_condition_unchanged'], $this->codes($result['warnings']));
        $this->assertSame('nodes.loop_1.config.condition', $result['warnings'][0]->path);
        $this->assertStringContainsString("'flow.done'", $result['warnings'][0]->message);
    }

    public function test_a_while_condition_a_body_node_writes_is_not_warned_about(): void
    {
        foreach ([
            ['ask', 'input', ['variable' => ['name' => 'done', 'storage' => 'session', 'type' => 'boolean']]],
            ['set', 'assign', ['operations' => [['variable' => ['name' => 'done', 'storage' => 'session'], 'value' => '1']]]],
            ['legacy', 'assign', ['target' => 'flow', 'key' => 'done', 'value' => '1']],
            ['fetch', 'call', ['result_mapping' => [['from' => 'body.ok', 'to' => ['name' => 'done', 'storage' => 'session']]]]],
            ['child', 'subflow', ['output_mapping' => ['done' => 'finished']]],
        ] as [$id, $type, $config]) {
            $result = $this->check(
                nodes: [$this->whileLoop('loop_1', 'done'), $this->node($id, $type, $config), $this->loopEnd('end_1', 'loop_1')],
                edges: [$this->edge('loop_1', $id, 'loop'), $this->edge($id, 'end_1')],
            );

            $this->assertSame([], $result['warnings'], "A '{$type}' node writing the variable must silence the warning.");
        }
    }

    public function test_a_write_outside_the_loop_body_does_not_count(): void
    {
        $result = $this->check(
            nodes: [
                $this->node('before', 'assign', ['target' => 'flow', 'key' => 'done', 'value' => '0']),
                $this->whileLoop('loop_1', 'done'),
                $this->node('say', 'send_message'),
                $this->loopEnd('end_1', 'loop_1'),
            ],
            edges: [
                $this->edge('before', 'loop_1'),
                $this->edge('loop_1', 'say', 'loop'),
                $this->edge('say', 'end_1'),
            ],
        );

        $this->assertSame(['loop_while_condition_unchanged'], $this->codes($result['warnings']));
    }

    public function test_a_condition_on_the_iterator_or_on_a_path_that_cannot_be_followed_is_not_warned_about(): void
    {
        $iterator = $this->check(
            nodes: [
                $this->loop('loop_1', ['mode' => 'while', 'condition' => ['left' => 'flow.iterator', 'operator' => 'lt', 'value' => 3]]),
                $this->node('say', 'send_message'),
                $this->loopEnd('end_1', 'loop_1'),
            ],
            edges: [$this->edge('loop_1', 'say', 'loop'), $this->edge('say', 'end_1')],
        );
        $module = $this->check(
            nodes: [
                $this->loop('loop_1', ['mode' => 'while', 'condition' => [
                    'left' => ['ref' => 'source', 'source' => 'module.shop', 'field' => 'open'], 'operator' => 'eq', 'value' => true,
                ]]),
                $this->node('say', 'send_message'),
                $this->loopEnd('end_1', 'loop_1'),
            ],
            edges: [$this->edge('loop_1', 'say', 'loop'), $this->edge('say', 'end_1')],
        );

        $this->assertSame([], $iterator['warnings']);
        $this->assertSame([], $module['warnings']);
    }

    public function test_a_write_to_the_parent_of_the_watched_path_counts_and_a_total_is_counted_only(): void
    {
        $length = $this->check(
            nodes: [
                $this->loop('loop_1', ['mode' => 'while', 'condition' => ['left' => 'contact.photos.length', 'operator' => 'lt', 'value' => 5]]),
                $this->node('ask', 'input', ['variable' => ['name' => 'photos', 'storage' => 'contact', 'type' => 'array']]),
                $this->loopEnd('end_1', 'loop_1'),
            ],
            edges: [$this->edge('loop_1', 'ask', 'loop'), $this->edge('ask', 'end_1')],
        );
        $total = $this->check(nodes: [
            $this->whileLoop('loop_1', 'done'),
            $this->node('set', 'assign', ['target' => 'flow', 'key' => 'iterator_total', 'value' => '1']),
        ]);

        $this->assertSame([], $length['warnings']);
        $this->assertSame([], $total['errors']);
    }

    public function test_a_solution_node_in_the_body_silences_the_warning(): void
    {
        $result = $this->check(
            nodes: [$this->whileLoop('loop_1', 'done'), $this->node('crm', 'crm.update'), $this->loopEnd('end_1', 'loop_1')],
            edges: [$this->edge('loop_1', 'crm', 'loop'), $this->edge('crm', 'end_1')],
            solutionTypes: ['crm.update'],
        );

        $this->assertSame([], $result['warnings']);
    }

    public function test_legacy_save_strings_of_send_message_and_call_count_as_writes(): void
    {
        foreach ([['send_message', 'save_to'], ['call', 'save_response_to']] as [$type, $key]) {
            $result = $this->check(
                nodes: [$this->whileLoop('loop_1', 'done'), $this->node('w', $type, [$key => 'done']), $this->loopEnd('end_1', 'loop_1')],
                edges: [$this->edge('loop_1', 'w', 'loop'), $this->edge('w', 'end_1')],
            );

            $this->assertSame([], $result['warnings'], "{$type}.{$key} is a write");
        }
    }

    public function test_a_template_node_in_the_body_silences_the_warning(): void
    {
        $result = $this->check(
            nodes: [$this->whileLoop('loop_1', 'done'), $this->node('tpl', 'template'), $this->loopEnd('end_1', 'loop_1')],
            edges: [$this->edge('loop_1', 'tpl', 'loop'), $this->edge('tpl', 'end_1')],
        );

        $this->assertSame([], $result['warnings']);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  list<string>                $solutionTypes
     *
     * @return array{errors: list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>, warnings: list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>}
     */
    private function check(array $nodes, array $edges = [], array $solutionTypes = []): array
    {
        $registry = $this->createStub(NodeHandlerRegistryInterface::class);
        $registry->method('ownerOf')->willReturnCallback(
            static fn (string $type): ?string => in_array($type, $solutionTypes, true) ? 'crm' : null,
        );

        $map = [];

        foreach ($nodes as $node) {
            $map[$node['id']] = $node;
        }

        return (new LoopConfigChecker($registry))->check($map, $edges);
    }

    /**
     * @param  list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>  $items
     *
     * @return list<string>
     */
    private function codes(array $items): array
    {
        return array_map(static fn ($item): string => $item->code, $items);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function node(string $id, string $type, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'version' => 1, 'config' => $config];
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function loop(string $id, array $config): array
    {
        return $this->node($id, 'loop', $config);
    }

    /**
     * @return array<string, mixed>
     */
    private function whileLoop(string $id, string $variable): array
    {
        return $this->loop($id, ['mode' => 'while', 'condition' => [
            'left'     => ['ref' => 'user_variable', 'variable' => ['name' => $variable, 'storage' => 'session']],
            'operator' => 'eq',
            'value'    => false,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loopEnd(string $id, string $loopId): array
    {
        return $this->node($id, 'loop_end', ['loop_node_id' => $loopId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function literal(mixed $value): array
    {
        return ['type' => 'literal', 'value' => $value];
    }

    /**
     * @return array<string, string>
     */
    private function edge(string $from, string $to, string $handle = 'default'): array
    {
        return ['from' => $from, 'to' => $to, 'handle' => $handle];
    }
}
