<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Support\ModuleDataAccessorRegistry;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use LogicException;
use Tests\TestCase;

final class ModuleDataAccessorRegistryTest extends TestCase
{
    public function test_registry_resolves_registered_prefix(): void
    {
        $registry = new ModuleDataAccessorRegistry();
        $accessor = new TestRegistryAccessor();

        $registry->register('module.hr', $accessor);

        $this->assertTrue($registry->has('module.hr'));
        $this->assertSame($accessor, $registry->resolve('module.hr'));
    }

    public function test_registry_rejects_duplicate_prefix(): void
    {
        $registry = new ModuleDataAccessorRegistry();
        $registry->register('module.hr', new TestRegistryAccessor());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Duplicate data accessor prefix 'module.hr'.");
        $registry->register('module.hr', new TestRegistryAccessor());
    }

    public function test_registry_rejects_reserved_prefixes(): void
    {
        $registry = new ModuleDataAccessorRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Namespace prefix 'flow.custom' is reserved by engine.");
        $registry->register('flow.custom', new TestRegistryAccessor());
    }

    public function test_registry_rejects_unknown_prefix_resolution(): void
    {
        $registry = new ModuleDataAccessorRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("No data accessor registered for namespace prefix 'module.crm'.");
        $registry->resolve('module.crm');
    }

    public function test_registry_cannot_be_modified_after_freeze(): void
    {
        $registry = new ModuleDataAccessorRegistry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $registry->register('module.hr', new TestRegistryAccessor());
    }
}

final class TestRegistryAccessor implements DataAccessorInterface
{
    public function namespace(): string
    {
        return 'hr';
    }

    public function get(string $key, string $contactId, string $tenantId): mixed
    {
        return null;
    }

    /**
     * @return string[]
     */
    public function supportedKeys(): array
    {
        return ['department'];
    }
}
