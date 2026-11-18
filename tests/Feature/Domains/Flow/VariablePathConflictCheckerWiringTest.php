<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\VariablePathConflictChecker;
use ReflectionProperty;
use Tests\TestCase;

final class VariablePathConflictCheckerWiringTest extends TestCase
{
    public function test_the_container_builds_the_validator_with_the_path_conflict_checker(): void
    {
        $service = $this->app->make(ValidateFlowService::class);

        $this->assertInstanceOf(VariablePathConflictChecker::class, (new ReflectionProperty($service, 'variablePaths'))->getValue($service));
    }

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
