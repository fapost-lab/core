<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Shared\Infrastructure;

use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tests\TestCase;

final class ModelAttributeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetInstance(ModelAttributeRegistry::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ModelAttributeRegistry::class);

        parent::tearDown();
    }

    public function test_register_and_resolve(): void
    {
        $registry = app(ModelAttributeRegistry::class);
        $model    = new ModelAttributeRegistryDummyModel();
        $model->setRawAttributes(['external_ref' => '01hzx']);

        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'computed',
            fn (Model $m): string => 'ref:' . $m->getAttribute('external_ref'),
        );

        $this->assertTrue($registry->has(ModelAttributeRegistryDummyModel::class, 'computed'));
        $this->assertSame('ref:01hzx', $registry->resolve(ModelAttributeRegistryDummyModel::class, 'computed', $model));
    }

    public function test_duplicate_registration_throws_logic_exception(): void
    {
        $registry = app(ModelAttributeRegistry::class);

        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'dup',
            fn (): int => 1,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already registered');

        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'dup',
            fn (): int => 2,
        );
    }

    public function test_serializable_returns_only_append_true_entries(): void
    {
        $registry = app(ModelAttributeRegistry::class);

        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'hidden',
            fn (): string => 'h',
            append: false,
        );
        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'visible',
            fn (): string => 'v',
            append: true,
        );

        $map = $registry->serializable(ModelAttributeRegistryDummyModel::class);

        $this->assertCount(1, $map);
        $this->assertArrayHasKey('visible', $map);
        $this->assertArrayNotHasKey('hidden', $map);

        $model = new ModelAttributeRegistryDummyModel();
        $this->assertSame('v', $map['visible']($model));
    }

    public function test_resolve_throws_when_attribute_not_registered(): void
    {
        $registry = app(ModelAttributeRegistry::class);
        $model    = new ModelAttributeRegistryDummyModel();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No model attribute');

        $registry->resolve(ModelAttributeRegistryDummyModel::class, 'missing', $model);
    }

    public function test_has_is_false_for_unknown_name(): void
    {
        $registry = app(ModelAttributeRegistry::class);

        $this->assertFalse($registry->has(ModelAttributeRegistryDummyModel::class, 'nope'));
    }

    public function test_register_throws_after_freeze(): void
    {
        $registry = app(ModelAttributeRegistry::class);
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ModelAttributeRegistry is frozen');

        $registry->register(
            ModelAttributeRegistryDummyModel::class,
            'late',
            fn (): string => 'value',
        );
    }
}

final class ModelAttributeRegistryDummyModel extends Model
{
    /** @var string */
    protected $table = 'model_attribute_registry_dummy_models';
}
