<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Infrastructure\Flow\CachedContentTranslator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

final class CachedContentTranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_translate_uses_cache_after_first_repository_read(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $repository->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola']);

        $translator = $this->translator($repository);

        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
    }

    public function test_translate_falls_back_to_base_language(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $repository->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn([]);
        $repository->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'en')
            ->andReturn(['welcome' => 'Hello']);

        $translator = $this->translator($repository, baseLanguage: 'en');

        $this->assertSame('Hello', $translator->translate('welcome', 'es'));
    }

    public function test_translate_returns_key_when_translation_is_missing(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $repository->shouldReceive('getAllForLanguage')->andReturn([]);

        $translator = $this->translator($repository, baseLanguage: 'en');

        $this->assertSame('missing.key', $translator->translate('missing.key', 'es'));
    }

    public function test_resolve_field_uses_language_then_base_then_first_key(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $translator = $this->translator($repository, baseLanguage: 'en');

        $this->assertSame('Hola', $translator->resolveField(['es' => 'Hola', 'en' => 'Hello'], 'es'));
        $this->assertSame('Hello', $translator->resolveField(['en' => 'Hello'], 'es'));
        $this->assertSame('Bonjour', $translator->resolveField(['fr' => 'Bonjour', 'de' => 'Hallo'], 'es'));
        $this->assertSame('', $translator->resolveField([], 'es'));
    }

    public function test_invalidate_forgets_cached_translations_for_language(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $repository->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola']);
        $repository->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola 2']);

        $translator = $this->translator($repository);

        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
        $translator->invalidate('tenant-1', 'es');
        $this->assertSame('Hola 2', $translator->translate('welcome', 'es'));
    }

    private function translator(
        TenantTranslationRepositoryInterface $repository,
        string $baseLanguage = 'en',
    ): CachedContentTranslator {
        $settings                        = (new ReflectionClass(TenantSettings::class))->newInstanceWithoutConstructor();
        $settings->content_base_language = $baseLanguage;
        $settings->fallback_language     = 'en';
        $cache                           = new CacheRepository(new ArrayStore());
        /** @var TenantContextInterface&MockInterface $tenantContext */
        $tenantContext = $this->mock(TenantContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->andReturn(
                new RuntimeTenant(id: 'tenant-1', schemaName: 'tenant_1'),
            );
        });

        return new CachedContentTranslator($repository, $settings, $cache, $tenantContext);
    }
}
