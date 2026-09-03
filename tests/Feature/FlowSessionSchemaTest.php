<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;

final class FlowSessionSchemaTest extends FeatureTestCase
{
    public function test_flow_sessions_foreign_keys_match_the_schema_contract(): void
    {
        /** @var array<int, array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string}> $definitions */
        $definitions = Schema::getForeignKeys('flow_sessions');

        $foreignKeys = collect($definitions)
            ->filter(fn (array $foreignKey): bool => 1 === count($foreignKey['columns']))
            ->mapWithKeys(fn (array $foreignKey): array => [
                $foreignKey['columns'][0] => [
                    'table'     => $foreignKey['foreign_table'],
                    'to'        => $foreignKey['foreign_columns'][0],
                    'on_delete' => mb_strtolower($foreignKey['on_delete']),
                ],
            ]);

        $this->assertSame('assistants', $foreignKeys->get('assistant_id')['table']);
        $this->assertSame('id', $foreignKeys->get('assistant_id')['to']);
        $this->assertSame('cascade', $foreignKeys->get('assistant_id')['on_delete']);

        $this->assertSame('contacts', $foreignKeys->get('contact_id')['table']);
        $this->assertSame('id', $foreignKeys->get('contact_id')['to']);
        $this->assertSame('cascade', $foreignKeys->get('contact_id')['on_delete']);

        $this->assertSame('flow_definitions', $foreignKeys->get('flow_definition_id')['table']);
        $this->assertSame('id', $foreignKeys->get('flow_definition_id')['to']);
        $this->assertSame('restrict', $foreignKeys->get('flow_definition_id')['on_delete']);
    }
}
