<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\State\Variables;

use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableResolver;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\State\Variables\VariableType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VariableResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: VariableStorage, 2: string, 3: string|null}>
     */
    public static function legacyPathProvider(): array
    {
        return [
            'flow prefix'         => ['flow.foo', VariableStorage::Session, 'foo', null],
            'contact flat'        => ['contact.foo', VariableStorage::Contact, 'foo', null],
            'contact with group'  => ['contact.bar.foo', VariableStorage::Contact, 'foo', 'bar'],
            'no prefix → session' => ['foo', VariableStorage::Session, 'foo', null],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedNameProvider(): array
    {
        return [
            'id'          => ['id'],
            'channel_id'  => ['channel_id'],
            'tenant_id'   => ['tenant_id'],
            'external_id' => ['external_id'],
            'meta'        => ['meta'],
            'language'    => ['language'],
            'is_blocked'  => ['is_blocked'],
            'created_at'  => ['created_at'],
            'updated_at'  => ['updated_at'],
        ];
    }

    public function test_resolves_session_variable_into_flow_namespace_path(): void
    {
        $resolver = new VariableResolver();
        $variable = new Variable(name: 'user_name', storage: VariableStorage::Session);

        $this->assertSame('flow.user_name', $resolver->resolveTargetPath($variable));
    }

    public function test_resolves_contact_variable_without_group(): void
    {
        $resolver = new VariableResolver();
        $variable = new Variable(name: 'first_name', storage: VariableStorage::Contact);

        $this->assertSame('contact.first_name', $resolver->resolveTargetPath($variable));
    }

    public function test_resolves_contact_variable_with_group(): void
    {
        $resolver = new VariableResolver();
        $variable = new Variable(name: 'street', storage: VariableStorage::Contact, group: 'address');

        $this->assertSame('contact.address.street', $resolver->resolveTargetPath($variable));
    }

    public function test_session_variable_cannot_have_group(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Variable(name: 'foo', storage: VariableStorage::Session, group: 'bar');
    }

    #[DataProvider('legacyPathProvider')]
    public function test_from_legacy_path_parses_known_formats(
        string $input,
        VariableStorage $expectedStorage,
        string $expectedName,
        ?string $expectedGroup,
    ): void {
        $resolver = new VariableResolver();
        $variable = $resolver->fromLegacyPath($input);

        $this->assertSame($expectedStorage, $variable->storage);
        $this->assertSame($expectedName, $variable->name);
        $this->assertSame($expectedGroup, $variable->group);
    }

    public function test_from_legacy_path_rejects_contact_path_deeper_than_one_group(): void
    {
        $resolver = new VariableResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->fromLegacyPath('contact.a.b.c');
    }

    public function test_from_legacy_path_rejects_unknown_namespace(): void
    {
        $resolver = new VariableResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->fromLegacyPath('module.hr.dept');
    }

    public function test_from_legacy_path_rejects_empty_input(): void
    {
        $resolver = new VariableResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->fromLegacyPath('   ');
    }

    public function test_from_legacy_path_rejects_nested_flow_path(): void
    {
        $resolver = new VariableResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->fromLegacyPath('flow.a.b');
    }

    #[DataProvider('reservedNameProvider')]
    public function test_reserved_names_are_rejected(string $reserved): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Variable(name: $reserved, storage: VariableStorage::Contact);
    }

    public function test_reserved_meta_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Variable(name: 'whatever', storage: VariableStorage::Contact, group: 'meta');
    }

    public function test_try_from_array_returns_null_when_payload_incomplete(): void
    {
        $this->assertNull(Variable::tryFromArray([]));
        $this->assertNull(Variable::tryFromArray(['name' => 'x']));
        $this->assertNull(Variable::tryFromArray(['storage' => 'session']));
    }

    public function test_try_from_array_throws_for_unknown_storage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Variable::tryFromArray(['name' => 'foo', 'storage' => 'galactic']);
    }

    public function test_try_from_array_treats_empty_group_as_null(): void
    {
        $variable = Variable::tryFromArray([
            'name'    => 'foo',
            'storage' => 'contact',
            'group'   => '',
        ]);

        $this->assertInstanceOf(Variable::class, $variable);
        $this->assertNull($variable->group);
    }

    public function test_try_from_array_builds_full_contact_variable(): void
    {
        $variable = Variable::tryFromArray([
            'name'    => 'street',
            'storage' => 'contact',
            'group'   => 'address',
            'type'    => 'text',
        ]);

        $this->assertInstanceOf(Variable::class, $variable);
        $this->assertSame(VariableStorage::Contact, $variable->storage);
        $this->assertSame('street', $variable->name);
        $this->assertSame('address', $variable->group);
        $this->assertSame(VariableType::Text, $variable->type);
    }

    public function test_try_from_array_rejects_invalid_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Variable::tryFromArray(['name' => '1bad', 'storage' => 'session']);
    }

    public function test_read_returns_null_when_state_reader_is_absent(): void
    {
        $resolver = new VariableResolver();
        $variable = new Variable(name: 'foo', storage: VariableStorage::Session);

        $context = new NodeExecutionContext(
            tenantId: 't',
            contactId: 'c',
            sessionId: 's',
            nodeId: 'n',
            idempotencyKey: 'i',
            platform: 'telegram',
        );

        $this->assertNull($resolver->read($variable, $context));
    }

    public function test_read_delegates_to_scoped_state_reader(): void
    {
        $reader = Mockery::mock(ScopedStateReaderInterface::class);
        $reader->shouldReceive('read')->once()->with('flow.foo')->andReturn('bar');

        $resolver = new VariableResolver();
        $variable = new Variable(name: 'foo', storage: VariableStorage::Session);

        $context = new NodeExecutionContext(
            tenantId: 't',
            contactId: 'c',
            sessionId: 's',
            nodeId: 'n',
            idempotencyKey: 'i',
            platform: 'telegram',
            stateReader: $reader,
        );

        $this->assertSame('bar', $resolver->read($variable, $context));
    }
}
