<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Validation\FlowGraphStructureValidator;
use PHPUnit\Framework\TestCase;

final class FlowGraphStructureValidatorTest extends TestCase
{
    public function test_a_realistic_flow_with_branch_loop_back_edge_and_comment_is_valid(): void
    {
        $errors = $this->validate(
            nodes: [
                $this->node('start', 'send_message'),
                $this->node('ask', 'input', ['variable' => ['name' => 'answer', 'type' => 'text', 'storage' => 'session']]),
                $this->node('decide', 'branch', ['rules' => [[
                    'handle' => 'yes',
                    'left'   => ['ref' => 'user_variable', 'variable' => ['name' => 'answer', 'storage' => 'session']],
                ]]]),
                $this->node('thanks', 'send_message'),
                $this->node('note', 'comment', ['text' => 'explains the loop']),
            ],
            edges: [
                $this->edge('start', 'ask'),
                $this->edge('ask', 'decide'),
                $this->edge('decide', 'thanks', 'yes'),
                // loop back-edge to a non-entry node
                $this->edge('decide', 'ask'),
                // the comment hangs off a step and continues nowhere
                $this->edge('thanks', 'note'),
            ],
        );

        $this->assertSame([], $errors);
    }

    public function test_duplicate_node_id_is_reported(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('a', 'send_message')],
            [],
        );

        $this->assertContains('duplicate_node_id', $this->codes($errors));
        $this->assertSame('nodes.a', $this->first($errors, 'duplicate_node_id')->path);
    }

    public function test_edge_to_unknown_node_is_reported_with_its_index(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message')],
            [$this->edge('a', 'ghost')],
        );

        $error = $this->first($errors, 'edge_unknown_node');

        $this->assertSame('edges.0', $error->path);
        $this->assertStringContainsString('ghost', $error->message);
    }

    public function test_edge_from_unknown_node_is_reported(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message')],
            [$this->edge('ghost', 'a')],
        );

        $this->assertContains('edge_unknown_node', $this->codes($errors));
    }

    public function test_two_edges_on_the_same_handle_are_reported(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [$this->edge('a', 'b'), $this->edge('a', 'c')],
        );

        $this->assertSame('edges.1', $this->first($errors, 'duplicate_edge_handle')->path);
    }

    public function test_a_missing_handle_counts_as_default(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [['from' => 'a', 'to' => 'b'], $this->edge('a', 'c', 'default')],
        );

        $this->assertContains('duplicate_edge_handle', $this->codes($errors));
    }

    public function test_distinct_handles_from_one_node_are_valid(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [$this->edge('a', 'b', 'yes'), $this->edge('a', 'c', 'no')],
        );

        $this->assertSame([], $errors);
    }

    public function test_an_empty_handle_is_its_own_handle_not_default(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [$this->edge('a', 'b', ''), $this->edge('a', 'c', 'default')],
        );

        $this->assertNotContains('duplicate_edge_handle', $this->codes($errors));
    }

    public function test_a_non_string_handle_is_skipped_by_the_duplicate_check(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [['from' => 'a', 'to' => 'b', 'handle' => 5], ['from' => 'a', 'to' => 'c', 'handle' => 5]],
        );

        $this->assertNotContains('duplicate_edge_handle', $this->codes($errors));
    }

    public function test_annotation_nodes_are_kept_when_stripping_is_off(): void
    {
        $nodes = [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('note', 'comment')];
        $edges = [$this->edge('a', 'b')];

        $this->assertSame([], (new FlowGraphStructureValidator())->validate($nodes, $edges));

        $unstripped = (new FlowGraphStructureValidator())->validate($nodes, $edges, stripAnnotations: false);

        $this->assertSame(['entry_node_count'], $this->codes($unstripped));
    }

    public function test_loop_buttons_and_branch_handles_produce_no_false_positives(): void
    {
        $errors = $this->validate(
            nodes: [
                $this->node('start', 'send_message'),
                $this->node('each', 'loop', ['collection' => 'items']),
                $this->node('body', 'send_message'),
                $this->node('end_body', 'loop_end', ['loop_node_id' => 'each']),
                $this->node('menu', 'send_message', ['buttons' => [['id' => 'btn_1'], ['id' => 'btn_2']]]),
                $this->node('decide', 'branch', ['rules' => [
                    ['handle' => 'r1', 'left' => ['ref' => 'source', 'source' => 'contact', 'field' => 'name']],
                    ['handle' => 'r2', 'left' => ['ref' => 'source', 'source' => 'contact', 'field' => 'phone']],
                ]]),
                $this->node('one', 'send_message'),
                $this->node('two', 'send_message'),
                $this->node('other', 'send_message'),
            ],
            edges: [
                $this->edge('start', 'each'),
                $this->edge('each', 'body', 'loop'),
                $this->edge('body', 'end_body'),
                $this->edge('each', 'menu', 'done'),
                $this->edge('menu', 'decide', 'btn_1'),
                $this->edge('menu', 'other', 'btn_2'),
                $this->edge('decide', 'one', 'r1'),
                $this->edge('decide', 'two', 'r2'),
                ['from' => 'decide', 'to' => 'other'],
            ],
        );

        $this->assertSame([], $errors);
    }

    public function test_two_entry_nodes_are_reported_with_their_ids(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [$this->edge('a', 'c')],
        );

        $error = $this->first($errors, 'entry_node_count');

        $this->assertStringContainsString('found 2: a, b', $error->message);
    }

    public function test_a_back_edge_to_the_entry_node_leaves_no_entry(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message')],
            [$this->edge('a', 'b'), $this->edge('b', 'a')],
        );

        $this->assertSame(['entry_node_count'], $this->codes($errors));
    }

    public function test_an_unreachable_cycle_is_reported_as_orphans(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('c', 'send_message')],
            [$this->edge('a', 'b'), $this->edge('c', 'c')],
        );

        // `c` has an incoming edge (itself) so the entry is `a` alone, and `c` is unreachable from it.
        $this->assertSame(['orphan_node'], $this->codes($errors));
        $this->assertSame('nodes.c', $errors[0]->path);
    }

    public function test_orphans_are_not_reported_when_the_entry_is_ambiguous(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message')],
            [],
        );

        $this->assertSame(['entry_node_count'], $this->codes($errors));
    }

    public function test_annotation_nodes_are_not_entries_or_orphans(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('b', 'send_message'), $this->node('note', 'comment')],
            [$this->edge('a', 'b')],
        );

        $this->assertSame([], $errors);
    }

    public function test_a_comment_between_two_steps_bridges_the_chain(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('note', 'comment'), $this->node('b', 'send_message')],
            [$this->edge('a', 'note'), $this->edge('note', 'b')],
        );

        $this->assertSame([], $errors);
    }

    public function test_input_with_both_new_and_legacy_save_target_conflicts(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'input', [
                'save_to'  => 'answer',
                'variable' => ['name' => 'answer', 'type' => 'text', 'storage' => 'session'],
            ])],
            [],
        );

        $this->assertSame(['variable_contract_conflict'], $this->codes($errors));
    }

    public function test_input_with_a_reserved_variable_name_is_invalid(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'input', ['variable' => ['name' => 'id', 'storage' => 'contact']])],
            [],
        );

        $error = $this->first($errors, 'variable_contract_invalid');

        $this->assertSame('nodes.a.config.variable', $error->path);
        $this->assertStringContainsString('reserved', $error->message);
    }

    public function test_input_without_any_save_target_is_left_to_validate_flow_service(): void
    {
        $this->assertSame([], $this->validate([$this->node('a', 'input', [])], []));
    }

    public function test_variable_without_storage_is_invalid(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message', ['save_to_variable' => ['name' => 'answer']])],
            [],
        );

        $this->assertSame(['variable_contract_invalid'], $this->codes($errors));
    }

    public function test_send_message_with_both_save_targets_conflicts(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message', [
                'save_to'          => 'answer',
                'save_to_variable' => ['name' => 'answer', 'storage' => 'session'],
            ])],
            [],
        );

        $this->assertSame(['variable_contract_conflict'], $this->codes($errors));
    }

    public function test_assign_with_operations_and_legacy_target_conflicts(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'assign', [
                'target'     => 'contact',
                'operations' => [],
            ])],
            [],
        );

        $this->assertSame(['variable_contract_conflict'], $this->codes($errors));
    }

    public function test_assign_operations_must_be_objects_with_a_variable(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'assign', ['operations' => ['nope', ['value' => 1]]])],
            [],
        );

        $this->assertSame(
            ['variable_contract_invalid_operation', 'variable_contract_invalid_operation'],
            $this->codes($errors),
        );
        $this->assertSame('nodes.a.config.operations.1.variable', $errors[1]->path);
    }

    public function test_assign_operations_must_target_unique_variables(): void
    {
        $variable = ['name' => 'score', 'storage' => 'contact', 'group' => 'quiz'];

        $errors = $this->validate(
            [$this->node('a', 'assign', ['operations' => [
                ['variable' => $variable],
                ['variable' => $variable],
            ]])],
            [],
        );

        $error = $this->first($errors, 'variable_contract_duplicate_target');

        $this->assertSame('nodes.a.config.operations.1.variable', $error->path);
    }

    public function test_session_variable_with_a_group_is_invalid(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'assign', ['operations' => [
                ['variable' => ['name' => 'score', 'storage' => 'session', 'group' => 'quiz']],
            ]])],
            [],
        );

        $this->assertSame(['variable_contract_invalid'], $this->codes($errors));
    }

    public function test_branch_rule_must_be_an_object(): void
    {
        $errors = $this->validate([$this->node('a', 'branch', ['rules' => ['nope']])], []);

        $this->assertSame(['branch_rules_invalid_rule'], $this->codes($errors));
    }

    public function test_branch_rule_without_left_is_legacy_and_accepted(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'branch', ['rules' => [['operator' => 'eq', 'value' => 'x']]])],
            [],
        );

        $this->assertSame([], $errors);
    }

    public function test_branch_user_variable_left_requires_a_name(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'branch', ['rules' => [['left' => ['ref' => 'user_variable']]]])],
            [],
        );

        $this->assertSame(['branch_rules_missing_variable_name'], $this->codes($errors));
    }

    public function test_branch_source_left_requires_source_and_field(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'branch', ['rules' => [
                ['left' => ['ref' => 'source', 'field' => 'name']],
                ['left' => ['ref' => 'source', 'source' => 'contact']],
            ]])],
            [],
        );

        $this->assertSame(['branch_rules_missing_source', 'branch_rules_missing_field'], $this->codes($errors));
    }

    public function test_branch_source_must_be_allowed(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'branch', ['rules' => [
                ['left' => ['ref' => 'source', 'source' => 'secrets', 'field' => 'x']],
                ['left' => ['ref' => 'source', 'source' => 'module.crm', 'field' => 'x']],
                ['left' => ['ref' => 'source', 'source' => 'contact', 'field' => 'x']],
            ]])],
            [],
        );

        $this->assertSame(['branch_rules_source_not_allowed'], $this->codes($errors));
        $this->assertSame('nodes.a.config.rules.0.left.source', $errors[0]->path);
    }

    public function test_branch_left_ref_must_be_known(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'branch', ['rules' => [['left' => ['ref' => 'magic']]]])],
            [],
        );

        $this->assertSame(['branch_rules_invalid_ref'], $this->codes($errors));
    }

    public function test_every_problem_is_collected_rather_than_the_first_only(): void
    {
        $errors = $this->validate(
            [$this->node('a', 'send_message'), $this->node('a', 'send_message'), $this->node('b', 'branch', ['rules' => ['x']])],
            [$this->edge('a', 'ghost'), $this->edge('a', 'ghost')],
        );

        $codes = $this->codes($errors);
        sort($codes);

        $this->assertSame(
            ['branch_rules_invalid_rule', 'duplicate_edge_handle', 'duplicate_node_id', 'edge_unknown_node', 'edge_unknown_node', 'entry_node_count'],
            $codes,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     *
     * @return list<FlowValidationErrorDto>
     */
    private function validate(array $nodes, array $edges): array
    {
        return (new FlowGraphStructureValidator())->validate($nodes, $edges);
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
     * @return array<string, mixed>
     */
    private function edge(string $from, string $to, string $handle = 'default'): array
    {
        return ['id' => "{$from}-{$handle}-{$to}", 'from' => $from, 'to' => $to, 'handle' => $handle];
    }

    /**
     * @param  list<FlowValidationErrorDto>  $errors
     *
     * @return list<string>
     */
    private function codes(array $errors): array
    {
        return array_map(static fn (FlowValidationErrorDto $error): string => $error->code, $errors);
    }

    /**
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function first(array $errors, string $code): FlowValidationErrorDto
    {
        foreach ($errors as $error) {
            if ($error->code === $code) {
                return $error;
            }
        }

        $this->fail("No error with code {$code}; got: " . implode(', ', $this->codes($errors)));
    }
}
