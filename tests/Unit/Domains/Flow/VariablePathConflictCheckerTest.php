<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Validation\NodeWriteCollector;
use App\Domains\Flow\Validation\VariablePathConflictChecker;
use Tests\TestCase;

final class VariablePathConflictCheckerTest extends TestCase
{
    public function test_a_contact_leaf_and_a_group_of_the_same_name_conflict(): void
    {
        $errors = $this->check([
            $this->input('a', ['name' => 'profile', 'storage' => 'contact']),
            $this->input('b', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('variable_path_conflict', $errors[0]->code);
        $this->assertSame('nodes.a', $errors[0]->path);
        $this->assertStringContainsString('"contact.profile"', $errors[0]->message);
        $this->assertStringContainsString('"contact.profile.name" (node b)', $errors[0]->message);
    }

    public function test_the_message_follows_the_locale(): void
    {
        $this->app->setLocale('ru');

        $errors = $this->check([
            $this->input('a', ['name' => 'profile', 'storage' => 'contact']),
            $this->input('b', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('«contact.profile» записывается как одно значение узлом a', $errors[0]->message);
        $this->assertStringContainsString('«contact.profile.name» (узел b)', $errors[0]->message);
    }

    public function test_the_order_of_the_nodes_does_not_matter(): void
    {
        $errors = $this->check([
            $this->input('b', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
            $this->input('a', ['name' => 'profile', 'storage' => 'contact']),
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('nodes.a', $errors[0]->path);
    }

    public function test_siblings_in_one_group_and_equal_paths_are_fine(): void
    {
        $this->assertSame([], $this->check([
            $this->input('a', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
            $this->input('b', ['name' => 'city', 'group' => 'profile', 'storage' => 'contact']),
            $this->input('c', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
        ]));
    }

    public function test_session_and_contact_paths_do_not_clash_with_each_other(): void
    {
        $this->assertSame([], $this->check([
            $this->input('a', ['name' => 'profile', 'storage' => 'session']),
            $this->input('b', ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']),
        ]));
    }

    public function test_legacy_paths_meet_the_new_shape(): void
    {
        $errors = $this->check([
            ['id' => 'a', 'type' => 'input', 'config' => ['save_to' => 'contact.profile']],
            ['id' => 'b', 'type' => 'send_message', 'config' => ['save_to_variable' => ['name' => 'name', 'group' => 'profile', 'storage' => 'contact']]],
        ]);

        $this->assertSame(['variable_path_conflict'], array_map(static fn (FlowValidationErrorDto $e): string => $e->code, $errors));
    }

    public function test_an_ask_person_answer_is_a_write_too(): void
    {
        $errors = $this->check([
            $this->input('a', ['name' => 'decision', 'storage' => 'contact']),
            ['id' => 'b', 'type' => 'ask_person', 'config' => ['save_to_variable' => ['name' => 'by', 'group' => 'decision', 'storage' => 'contact']]],
        ]);

        $this->assertSame(['variable_path_conflict'], array_map(static fn (FlowValidationErrorDto $e): string => $e->code, $errors));
        $this->assertStringContainsString('"contact.decision.by" (node b)', $errors[0]->message);
    }

    public function test_a_legacy_assign_and_a_call_mapping_are_writes_too(): void
    {
        $errors = $this->check([
            ['id' => 'a', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'order', 'value' => '1']],
            ['id' => 'b', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'order.id', 'value' => '1']],
            ['id' => 'c', 'type' => 'call', 'config' => ['result_mapping' => [['from' => 'body.x', 'to' => ['name' => 'plan', 'storage' => 'contact']]]]],
            ['id' => 'd', 'type' => 'call', 'config' => ['save_to_variable' => ['name' => 'tier', 'group' => 'plan', 'storage' => 'contact']]],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('"flow.order"', $errors[0]->message);
        $this->assertStringContainsString('"contact.plan"', $errors[1]->message);
    }

    public function test_new_assign_operations_count_and_malformed_targets_are_ignored(): void
    {
        $errors = $this->check([
            ['id' => 'a', 'type' => 'assign', 'config' => ['operations' => [
                ['variable' => ['name' => 'kyc', 'storage' => 'contact'], 'value' => '1'],
                'garbage',
                ['variable' => ['name' => 'id', 'storage' => 'contact']],
            ]]],
            $this->input('b', ['name' => 'status', 'group' => 'kyc', 'storage' => 'contact']),
            ['id' => 'c', 'type' => 'input', 'config' => ['save_to' => 'flow.a.b.c']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"contact.kyc"', $errors[0]->message);
    }

    public function test_call_save_response_to_and_subflow_output_mapping_are_session_writes(): void
    {
        $errors = $this->check([
            ['id' => 'a', 'type' => 'subflow', 'config' => ['flow_id' => 'x', 'output_mapping' => ['res' => 'child_var']]],
            ['id' => 'b', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'res.id', 'value' => '1']],
            ['id' => 'c', 'type' => 'call', 'config' => ['save_response_to' => 'flow.a.b']],
            ['id' => 'd', 'type' => 'input', 'config' => ['save_to' => 'a']],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('"flow.res"', $errors[0]->message);
        $this->assertStringContainsString('"flow.a"', $errors[1]->message);
    }

    public function test_a_loop_counter_and_its_total_are_session_writes(): void
    {
        $errors = $this->check([
            ['id' => 'l', 'type' => 'loop', 'config' => ['mode' => 'counted', 'iterator_name' => 'i']],
            ['id' => 'a', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'i.x', 'value' => '1']],
            ['id' => 'b', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'i_total.x', 'value' => '1']],
        ]);

        $this->assertCount(2, $errors);
        $this->assertSame('nodes.l', $errors[0]->path);

        $this->assertSame([], $this->check([
            ['id' => 'l', 'type' => 'loop', 'config' => ['mode' => 'while', 'iterator_name' => 'i']],
            ['id' => 'b', 'type' => 'assign', 'config' => ['target' => 'flow', 'key' => 'i_total.x', 'value' => '1']],
        ]));
    }

    /**
     * @param  array<string, mixed>  $variable
     *
     * @return array<string, mixed>
     */
    private function input(string $id, array $variable): array
    {
        return ['id' => $id, 'type' => 'input', 'config' => ['variable' => $variable]];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<FlowValidationErrorDto>
     */
    private function check(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            $map[$node['id']] = $node;
        }

        return (new VariablePathConflictChecker(new NodeWriteCollector()))->check($map);
    }
}
