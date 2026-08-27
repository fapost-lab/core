<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\History;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\History\DefaultHistoryWriter;
use App\Domains\Flow\History\HistoryWriterFactory;
use App\Domains\Flow\History\NoOpHistoryWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use FAPost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Str;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;

final class HistoryWriterTest extends FeatureTestCase
{
    public function test_default_writer_persists_state_change_event(): void
    {
        [$tenantId, $session] = $this->makeSession(loggingEnabled: true);

        $writer = new DefaultHistoryWriter(new NullLogger());
        $writer->record(
            HistoryEventType::StateChange,
            $tenantId,
            (string) $session->getKey(),
            'node-A',
            path: 'flow.attempts',
            oldValue: 0,
            newValue: 1,
            metadata: ['source' => 'test'],
        );

        $entry = FlowSessionHistoryEntry::query()->firstOrFail();

        $this->assertSame($tenantId, $entry->tenant_id);
        $this->assertSame((string) $session->getKey(), $entry->session_id);
        $this->assertSame('node-A', $entry->node_id);
        $this->assertSame(HistoryEventType::StateChange, $entry->event_type);
        $this->assertSame('flow.attempts', $entry->path);
        $this->assertSame(['value' => 0], $entry->old_value);
        $this->assertSame(['value' => 1], $entry->new_value);
        $this->assertSame(['source' => 'test'], $entry->metadata);
    }

    public function test_default_writer_handles_array_values_without_wrapping(): void
    {
        [$tenantId, $session] = $this->makeSession(loggingEnabled: true);

        $writer = new DefaultHistoryWriter(new NullLogger());
        $writer->record(
            HistoryEventType::SubflowStarted,
            $tenantId,
            (string) $session->getKey(),
            'subflow-1',
            metadata: ['child_session_id' => 'child-x'],
        );

        $entry = FlowSessionHistoryEntry::query()->firstOrFail();

        $this->assertSame(HistoryEventType::SubflowStarted, $entry->event_type);
        $this->assertSame(['child_session_id' => 'child-x'], $entry->metadata);
        $this->assertNull($entry->old_value);
        $this->assertNull($entry->new_value);
    }

    public function test_noop_writer_does_not_persist_anything(): void
    {
        [$tenantId, $session] = $this->makeSession(loggingEnabled: false);

        $writer = new NoOpHistoryWriter();
        $writer->record(
            HistoryEventType::NodeEntered,
            $tenantId,
            (string) $session->getKey(),
            'node-A',
        );

        $this->assertSame(0, FlowSessionHistoryEntry::query()->count());
    }

    public function test_factory_returns_default_writer_when_logging_enabled(): void
    {
        $factory   = $this->app->make(HistoryWriterFactory::class);
        [, , $def] = $this->makeSession(loggingEnabled: true, returnDefinition: true);

        $writer = $factory->for($def);

        $this->assertInstanceOf(DefaultHistoryWriter::class, $writer);
    }

    public function test_factory_returns_noop_writer_when_logging_disabled(): void
    {
        $factory   = $this->app->make(HistoryWriterFactory::class);
        [, , $def] = $this->makeSession(loggingEnabled: false, returnDefinition: true);

        $writer = $factory->for($def);

        $this->assertInstanceOf(NoOpHistoryWriter::class, $writer);
    }

    /**
     * @return array{string, FlowSession}|array{string, FlowSession, FlowDefinition}
     */
    private function makeSession(bool $loggingEnabled, bool $returnDefinition = false): array
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id'         => $tenantId,
            'flow_id'           => Str::uuid()->toString(),
            'version'           => 1,
            'name'              => 'Test',
            'nodes'             => [],
            'edges'             => [],
            'is_active'         => true,
            'expression_engine' => 'template',
            'logging_enabled'   => $loggingEnabled,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-A',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 1,
        ]);

        return $returnDefinition ? [$tenantId, $session, $definition] : [$tenantId, $session];
    }
}
