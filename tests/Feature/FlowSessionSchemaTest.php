<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

final class FlowSessionSchemaTest extends FeatureTestCase
{
    public function test_flow_sessions_foreign_keys_match_the_schema_contract(): void
    {
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('flow_sessions')"))
            ->mapWithKeys(fn (object $foreignKey): array => [
                $foreignKey->from => [
                    'table'     => $foreignKey->table,
                    'to'        => $foreignKey->to,
                    'on_delete' => mb_strtolower((string) $foreignKey->on_delete),
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
