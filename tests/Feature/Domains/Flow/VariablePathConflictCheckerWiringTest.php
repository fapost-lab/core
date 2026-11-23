<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Services\ValidateFlowService;
use Tests\TestCase;

final class VariablePathConflictCheckerWiringTest extends TestCase
{
    public function test_validating_a_flow_reports_the_conflict_at_the_writing_node(): void
    {
        $result = $this->app->make(ValidateFlowService::class)->execute(
            nodes: [
                ['id' => 'a', 'type' => 'assign', 'config' => ['target' => 'contact', 'key' => 'profile', 'value' => '1']],
                ['id' => 'b', 'type' => 'assign', 'config' => ['target' => 'contact', 'key' => 'profile.name', 'value' => '1']],
            ],
            edges: [['from' => 'a', 'to' => 'b', 'handle' => 'default']],
        );

        $codes = array_map(static fn ($error): string => $error->code, $result->errors);

        $this->assertContains('variable_path_conflict', $codes);
        $this->assertContains('nodes.a', array_map(static fn ($error): string => $error->path, $result->errors));
    }
}
