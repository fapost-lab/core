<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\State;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\History\NoOpHistoryWriter;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\State\Exceptions\ReservedContactPathException;
use App\Domains\Flow\State\Exceptions\StructuralPathConflictException;
use App\Domains\Flow\State\Writers\ContactWriter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;

final class ContactWriterTest extends FeatureTestCase
{
    public function test_writes_canonical_column_language(): void
    {
        $contact = $this->makeContact();

        $this->writer($contact)->write('contact.language', 'es');

        $contact->refresh();
        $this->assertSame('es', $contact->language);
    }

    public function test_writes_canonical_column_is_authenticated(): void
    {
        $contact = $this->makeContact();
        $this->assertFalse($contact->fresh()->is_authenticated);

        $this->writer($contact)->write('contact.is_authenticated', true);

        $contact->refresh();
        $this->assertTrue($contact->is_authenticated);
    }

    public function test_writes_top_level_attribute(): void
    {
        $contact = $this->makeContact();

        $this->writer($contact)->write('contact.first_name', 'Иван');

        $contact->refresh();
        $this->assertSame('Иван', $contact->attributes['first_name'] ?? null);
    }

    public function test_writes_nested_attribute_in_group(): void
    {
        $contact = $this->makeContact();
        $writer  = $this->writer($contact);

        $writer->write('contact.form.input1', 'value1');
        $writer->write('contact.form.input2', 'value2');

        $contact->refresh();
        $this->assertSame(['input1' => 'value1', 'input2' => 'value2'], $contact->attributes['form'] ?? null);
    }

    public function test_rejects_reserved_id_column(): void
    {
        $writer = $this->writer($this->makeContact());

        $this->expectException(ReservedContactPathException::class);
        $writer->write('contact.id', 'new-id');
    }

    public function test_rejects_reserved_meta_group(): void
    {
        $writer = $this->writer($this->makeContact());

        $this->expectException(ReservedContactPathException::class);
        $writer->write('contact.meta.username', '@new');
    }

    public function test_rejects_depth_violation(): void
    {
        $writer = $this->writer($this->makeContact());

        $this->expectException(InvalidArgumentException::class);
        $writer->write('contact.form.address.city', 'Москва');
    }

    public function test_rejects_writing_nested_into_canonical_column(): void
    {
        $writer = $this->writer($this->makeContact());

        $this->expectException(InvalidArgumentException::class);
        $writer->write('contact.language.deep', 'nope');
    }

    public function test_rejects_leaf_vs_group_conflict(): void
    {
        $contact = $this->makeContact(attributes: ['profile' => 'simple-string']);
        $writer  = $this->writer($contact);

        $this->expectException(StructuralPathConflictException::class);
        $writer->write('contact.profile.first', 'X');
    }

    public function test_rejects_group_vs_leaf_conflict(): void
    {
        $contact = $this->makeContact(attributes: ['profile' => ['first' => 'A']]);
        $writer  = $this->writer($contact);

        $this->expectException(StructuralPathConflictException::class);
        $writer->write('contact.profile', 'simple-string');
    }

    public function test_rejects_path_outside_contact_namespace(): void
    {
        $writer = $this->writer($this->makeContact());

        $this->expectException(InvalidArgumentException::class);
        $writer->write('flow.attempts', 1);
    }

    public function test_writes_emit_history_when_writer_records(): void
    {
        $tenantId = (string) Str::uuid();
        $contact  = Contact::factory()->forTenant($tenantId)->create();

        // history requires a real session row because of the FK constraint
        $assistant  = \App\Domains\Assistant\Models\Assistant::factory()->create(['tenant_id' => $tenantId]);
        $definition = \App\Domains\Flow\Models\FlowDefinition::query()->create([
            'tenant_id'         => $tenantId,
            'flow_id'           => Str::uuid()->toString(),
            'version'           => 1,
            'name'              => 'Test',
            'nodes'             => [],
            'edges'             => [],
            'is_active'         => true,
            'expression_engine' => 'template',
            'logging_enabled'   => true,
        ]);
        $session = \App\Domains\Flow\Models\FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-A',
            'state'              => [],
            'status'             => \App\Domains\Flow\Enums\FlowSessionStatus::Active,
            'version'            => 1,
        ]);

        $writer = new ContactWriter(
            contact: $contact,
            sessionId: (string) $session->getKey(),
            nodeId: 'node-A',
            connection: app(ConnectionInterface::class),
            historyWriter: new \App\Domains\Flow\History\DefaultHistoryWriter(new NullLogger()),
        );

        $writer->write('contact.language', 'fr');

        $entry = FlowSessionHistoryEntry::query()->firstOrFail();
        $this->assertSame('contact.language', $entry->path);
        $this->assertSame('node-A', $entry->node_id);
        $this->assertSame(['value' => 'fr'], $entry->new_value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeContact(array $attributes = []): Contact
    {
        $tenantId = (string) Str::uuid();

        return Contact::factory()->forTenant($tenantId)->create([
            'attributes' => $attributes,
        ]);
    }

    private function writer(Contact $contact): ContactWriter
    {
        return new ContactWriter(
            contact: $contact,
            sessionId: 'session-1',
            nodeId: 'node-A',
            connection: app(ConnectionInterface::class),
            historyWriter: new NoOpHistoryWriter(),
        );
    }
}
