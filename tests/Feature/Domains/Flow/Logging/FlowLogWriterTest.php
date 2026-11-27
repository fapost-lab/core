<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Logging;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\RecordingConnectionResolver;

final class FlowLogWriterTest extends FeatureTestCase
{
    public function test_it_writes_flow_log_entry(): void
    {
        $session = $this->createSession();

        $writer = $this->app->make(FlowLogWriter::class);
        $writer->write(new FlowLogEntry(
            sessionId: (string) $session->getKey(),
            nodeId: 'node-1',
            nodeType: 'send_message',
            nodeVersion: 1,
            status: FlowLogStatus::Executed,
            sourceHandle: 'default',
            stateChanges: ['flow.answer' => 'ok'],
            resolved: ['flow.answer' => 'ok'],
            error: null,
        ));

        $this->assertDatabaseHas('flow_logs', [
            'session_id' => (string) $session->getKey(),
            'node_id'    => 'node-1',
            'status'     => 'executed',
        ]);

        if ('pgsql' !== DB::getDriverName()) {
            return;
        }

        $row = DB::selectOne("SELECT tableoid::regclass::text AS partition_name FROM flow_logs WHERE session_id = ? LIMIT 1", [
            (string) $session->getKey(),
        ]);

        $this->assertNotNull($row);
        $this->assertStringStartsWith('flow_logs_', (string) $row->partition_name);
    }

    public function test_the_write_resolves_its_connection_when_it_writes(): void
    {
        $session  = $this->createSession();
        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);
        $this->app->forgetScopedInstances();

        $writer = $this->app->make(FlowLogWriter::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the writer is built would outlive a tenant switch.');

        $writer->write(new FlowLogEntry(
            sessionId: (string) $session->getKey(),
            nodeId: 'node-1',
            nodeType: 'send_message',
            nodeVersion: 1,
            status: FlowLogStatus::Executed,
            sourceHandle: 'default',
            stateChanges: null,
            resolved: null,
            error: null,
        ));

        $this->assertNotEmpty($resolver->resolved);
        $this->assertSame([DB::getDefaultConnection()], array_values(array_unique($resolver->resolved)));
    }

    private function createSession(): FlowSession
    {
        $tenantId   = (string) Str::uuid();
        $assistant  = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact    = Contact::factory()->forTenant($tenantId)->create();
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Flow',
            'nodes'     => [
                [
                    'id'      => 'n1',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'n1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);
    }
}
