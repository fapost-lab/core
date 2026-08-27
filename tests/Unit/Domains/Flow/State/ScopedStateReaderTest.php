<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\State;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\State\Readers\ScopedStateReader;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use Tests\TestCase;

final class ScopedStateReaderTest extends TestCase
{
    public function test_reads_session_state_namespaces(): void
    {
        $reader = new ScopedStateReader(
            sessionState: [
                'flow'   => ['attempts' => 3, 'group' => ['key' => 'value']],
                'system' => ['language' => 'en'],
                'rag'    => ['confidence' => 'high'],
                'call'   => ['response' => ['code' => 200]],
            ],
            contact: $this->contact(),
            accessors: $this->emptyRegistry(),
        );

        $this->assertSame(3, $reader->read('flow.attempts'));
        $this->assertSame('value', $reader->read('flow.group.key'));
        $this->assertSame('en', $reader->read('system.language'));
        $this->assertSame('high', $reader->read('rag.confidence'));
        $this->assertSame(200, $reader->read('call.response.code'));
    }

    public function test_reads_contact_canonical_columns(): void
    {
        $contact            = $this->contact();
        $contact->language  = 'es';
        $contact->tenant_id = 't-99';

        $reader = new ScopedStateReader(
            sessionState: [],
            contact: $contact,
            accessors: $this->emptyRegistry(),
        );

        $this->assertSame('es', $reader->read('contact.language'));
        $this->assertSame('t-99', $reader->read('contact.tenant_id'));
    }

    public function test_reads_contact_is_authenticated_canonical_column(): void
    {
        $contact                   = $this->contact();
        $contact->is_authenticated = true;

        $reader = new ScopedStateReader(
            sessionState: [],
            contact: $contact,
            accessors: $this->emptyRegistry(),
        );

        // Must come from the canonical column, not the attributes JSONB.
        $this->assertTrue($reader->read('contact.is_authenticated'));
    }

    public function test_reads_contact_attributes_leaf_and_nested(): void
    {
        $contact             = $this->contact();
        $contact->attributes = [
            'first_name' => 'Иван',
            'form'       => ['input1' => 'A', 'input2' => 'B'],
        ];

        $reader = new ScopedStateReader(
            sessionState: [],
            contact: $contact,
            accessors: $this->emptyRegistry(),
        );

        $this->assertSame('Иван', $reader->read('contact.first_name'));
        $this->assertSame('A', $reader->read('contact.form.input1'));
        $this->assertSame(['input1' => 'A', 'input2' => 'B'], $reader->read('contact.form'));
    }

    public function test_returns_null_for_unknown_paths(): void
    {
        $reader = new ScopedStateReader(
            sessionState: [],
            contact: $this->contact(),
            accessors: $this->emptyRegistry(),
        );

        $this->assertNull($reader->read('flow.unknown'));
        $this->assertNull($reader->read('contact.no_such_attr'));
        $this->assertNull($reader->read('module.x.y'));
        $this->assertNull($reader->read('foobar.x'));
    }

    public function test_resolves_module_via_data_accessor(): void
    {
        $accessor = new class () implements DataAccessorInterface {
            public function namespace(): string
            {
                return 'hr';
            }

            public function get(string $key, string $contactId, string $tenantId): mixed
            {
                return 'department-' . $key;
            }

            public function supportedKeys(): array
            {
                return ['department'];
            }
        };

        $registry = $this->createMock(DataAccessorRegistryInterface::class);
        $registry->method('has')->with('hr')->willReturn(true);
        $registry->method('resolve')->with('hr')->willReturn($accessor);

        $reader = new ScopedStateReader(
            sessionState: [],
            contact: $this->contact(),
            accessors: $registry,
        );

        $this->assertSame('department-department', $reader->read('module.hr.department'));
    }

    private function contact(): Contact
    {
        $contact             = new Contact();
        $contact->id         = 'c-1';
        $contact->tenant_id  = 't-1';
        $contact->attributes = [];

        return $contact;
    }

    private function emptyRegistry(): DataAccessorRegistryInterface
    {
        $registry = $this->createMock(DataAccessorRegistryInterface::class);
        $registry->method('has')->willReturn(false);

        return $registry;
    }
}
