<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Handlers\AssignNodeHandler;
use App\Domains\Flow\Handlers\BranchNodeHandler;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\EmitEventNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\RagQueryNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the optional `sections` block in NodeHandler::configSchema(). Each
 * section is a UI hint for the generic SchemaConfigRenderer: it groups
 * existing field keys, it does not declare new ones. A typo here would
 * silently hide a field from the builder, so we check the contract here
 * rather than at runtime.
 */
final class NodeHandlerSchemaSectionsTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function handlerProvider(): array
    {
        return [
            'assign'     => [AssignNodeHandler::class],
            'branch'     => [BranchNodeHandler::class],
            'call'       => [CallNodeHandler::class],
            'rag_query'  => [RagQueryNodeHandler::class],
            'emit_event' => [EmitEventNodeHandler::class],
            'input'      => [InputNodeHandler::class],
            'send'       => [SendMessageNodeHandler::class],
            'subflow'    => [SubflowNodeHandler::class],
        ];
    }

    #[DataProvider('handlerProvider')]
    public function test_sections_reference_only_declared_fields(string $handlerClass): void
    {
        $handler = $this->app->make($handlerClass);
        $schema  = $handler->configSchema();

        $this->assertArrayHasKey('sections', $schema, "{$handlerClass} should declare sections");

        $sections = $schema['sections'];
        $this->assertIsArray($sections);
        $this->assertNotEmpty($sections);

        $reserved      = ['required', 'sections'];
        $declaredKeys  = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }
            if (is_array($value) && isset($value['type'])) {
                $declaredKeys[] = $key;
            }
        }

        $referenced = [];
        foreach ($sections as $section) {
            $this->assertIsArray($section);
            $this->assertArrayHasKey('key', $section);
            $this->assertArrayHasKey('label', $section);
            $this->assertArrayHasKey('fields', $section);
            $this->assertIsArray($section['fields']);

            foreach ($section['fields'] as $fieldKey) {
                $this->assertIsString($fieldKey);
                $this->assertContains(
                    $fieldKey,
                    $declaredKeys,
                    "{$handlerClass}: section '{$section['key']}' references unknown field '{$fieldKey}'",
                );
                $referenced[] = $fieldKey;
            }
        }

        $missing = array_diff($declaredKeys, $referenced);
        $this->assertEmpty(
            $missing,
            "{$handlerClass}: fields not assigned to any section: " . implode(', ', $missing),
        );
    }
}
