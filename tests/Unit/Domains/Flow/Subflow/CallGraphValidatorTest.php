<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Subflow;

use App\Domains\Flow\Subflow\CallGraphRepository;
use App\Domains\Flow\Subflow\CallGraphValidator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

final class CallGraphValidatorTest extends TestCase
{

    public function test_direct_recursion_is_rejected(): void
    {
        $repo = $this->repo([]);
        $validator = new CallGraphValidator($repo);

        $violations = $validator->validate('flow-a', ['flow-a']);

        $this->assertNotEmpty($violations);
        $this->assertSame('subflow_direct_recursion', $violations[0]->code);
    }

    public function test_indirect_recursion_is_detected_via_existing_edges(): void
    {
        // Existing graph: B → A. Adding A → B closes the cycle A → B → A.
        $repo = $this->repo([
            'flow-b' => ['flow-a'],
        ]);

        $validator = new CallGraphValidator($repo);

        $violations = $validator->validate('flow-a', ['flow-b']);

        $codes = array_map(static fn ($v) => $v->code, $violations);
        $this->assertContains('subflow_indirect_recursion', $codes);
    }

    public function test_depth_within_limit_passes(): void
    {
        // Existing: B → C. Adding A → B yields chain A → B → C, depth 3.
        $repo = $this->repo([
            'flow-b' => ['flow-c'],
        ]);

        $validator = new CallGraphValidator($repo);

        $violations = $validator->validate('flow-a', ['flow-b']);

        $this->assertSame([], $violations);
    }

    public function test_depth_exceeding_limit_is_rejected(): void
    {
        // Existing: B → C → D. Adding A → B yields A → B → C → D, depth 4.
        $repo = $this->repo([
            'flow-b' => ['flow-c'],
            'flow-c' => ['flow-d'],
        ]);

        $validator = new CallGraphValidator($repo);

        $violations = $validator->validate('flow-a', ['flow-b']);

        $codes = array_map(static fn ($v) => $v->code, $violations);
        $this->assertContains('subflow_depth_exceeded', $codes);
    }

    public function test_no_callees_passes_immediately(): void
    {
        $repo = $this->repo([]);
        $validator = new CallGraphValidator($repo);

        $this->assertSame([], $validator->validate('flow-a', []));
    }

    /**
     * Builds a repository backed by a fresh in-memory SQLite database, seeded
     * with the requested edges. Avoids mocking the {@code final} repository.
     *
     * @param  array<string, list<string>>  $edges  caller flow_id → list of callee flow_ids
     */
    private function repo(array $edges): CallGraphRepository
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $connection = $capsule->getConnection();
        $connection->getSchemaBuilder()->create('flow_callgraph_edges', function ($table): void {
            $table->string('caller_flow_id');
            $table->string('callee_flow_id');
            $table->string('caller_definition_id');
        });

        foreach ($edges as $caller => $callees) {
            foreach ($callees as $callee) {
                $connection->table('flow_callgraph_edges')->insert([
                    'caller_flow_id'       => $caller,
                    'callee_flow_id'       => $callee,
                    'caller_definition_id' => "def-for-{$caller}",
                ]);
            }
        }

        return new CallGraphRepository($connection);
    }
}
