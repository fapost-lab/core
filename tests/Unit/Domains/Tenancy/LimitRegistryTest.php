<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Services\LimitRegistry;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use LogicException;
use Tests\TestCase;

final class LimitRegistryTest extends TestCase
{
    public function test_registers_and_finds_a_limit(): void
    {
        $registry = new LimitRegistry();
        $limit    = $this->limit('contacts');

        $registry->register($limit);

        $this->assertSame($limit, $registry->find('contacts'));
        $this->assertNull($registry->find('missing'));
    }

    public function test_duplicate_key_throws(): void
    {
        $registry = new LimitRegistry();
        $registry->register($this->limit('contacts'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already registered');

        $registry->register($this->limit('contacts'));
    }

    public function test_frozen_registry_rejects_registration(): void
    {
        $registry = new LimitRegistry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('frozen');

        $registry->register($this->limit('contacts'));
    }

    public function test_all_is_ordered_by_key(): void
    {
        $registry = new LimitRegistry();
        $registry->register($this->limit('storage'));
        $registry->register($this->limit('assistants'));
        $registry->register($this->limit('contacts'));

        $this->assertSame(
            ['assistants', 'contacts', 'storage'],
            array_map(static fn (LimitDefinition $l): string => $l->key, $registry->all()),
        );
    }

    public function test_core_registers_the_assistants_limit_in_the_container_registry(): void
    {
        $limit = $this->app->make(LimitRegistryInterface::class)->find('assistants');

        $this->assertNotNull($limit);
        $this->assertSame('Assistants', $limit->label);
        $this->assertSame(LimitKind::Records, $limit->kind);
        $this->assertSame($this->app->make(LimitRegistryInterface::class), $this->app->make(LimitRegistry::class));
    }

    private function limit(string $key): LimitDefinition
    {
        return new LimitDefinition($key, ucfirst($key), $key, LimitKind::Records);
    }
}
