<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Shared\Models;

use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use App\Domains\Shared\Models\BaseModel;
use Tests\TestCase;

final class BaseModelExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(ModelAttributeRegistry::class);

        parent::tearDown();
    }

    public function test_magic_get_resolves_registered_attribute(): void
    {
        $registry = app(ModelAttributeRegistry::class);
        $registry->register(
            BaseModelExtensionFixture::class,
            'computed_label',
            fn (BaseModelExtensionFixture $model): string => 'fixed-' . $model->getAttribute('slug'),
            append: false,
        );

        $model = new BaseModelExtensionFixture();
        $model->setRawAttributes(['slug' => 'abc']);

        $this->assertSame('fixed-abc', $model->computed_label);
    }

    public function test_to_array_includes_only_append_true_registry_attributes(): void
    {
        $registry = app(ModelAttributeRegistry::class);
        $registry->register(
            BaseModelExtensionFixture::class,
            'internal_only',
            fn (): int => 1,
            append: false,
        );
        $registry->register(
            BaseModelExtensionFixture::class,
            'public_extra',
            fn (): string => 'x',
            append: true,
        );

        $model = new BaseModelExtensionFixture();
        $model->setRawAttributes(['slug' => 'z']);

        $array = $model->toArray();

        $this->assertArrayHasKey('public_extra', $array);
        $this->assertSame('x', $array['public_extra']);
        $this->assertArrayNotHasKey('internal_only', $array);
    }
}

final class BaseModelExtensionFixture extends BaseModel
{
    /** @var string */
    protected $table = 'base_model_extension_fixtures';

    /**
     * @var list<string>
     */
    protected $fillable = ['slug'];
}
