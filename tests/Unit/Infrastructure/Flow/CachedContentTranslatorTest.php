<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Translations\InMemorySystemTranslationCatalog;
use App\Domains\Flow\Translations\SystemTranslationEntry;
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
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola']);

        $translator = $this->translator($tenantRepo);

        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
    }

    public function test_translate_falls_back_to_base_language(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn([]);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'en')
            ->andReturn(['welcome' => 'Hello']);

        $translator = $this->translator($tenantRepo, baseLanguage: 'en');

        $this->assertSame('Hello', $translator->translate('welcome', 'es'));
    }

    public function test_translate_returns_key_when_translation_is_missing(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $translator = $this->translator($tenantRepo, baseLanguage: 'en');

        $this->assertSame('missing.key', $translator->translate('missing.key', 'es'));
    }

    public function test_resolve_field_uses_language_then_base_then_first_key(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $translator = $this->translator($tenantRepo, baseLanguage: 'en');

        $this->assertSame('Hola', $translator->resolveField(['es' => 'Hola', 'en' => 'Hello'], 'es'));
        $this->assertSame('Hello', $translator->resolveField(['en' => 'Hello'], 'es'));
        $this->assertSame('Bonjour', $translator->resolveField(['fr' => 'Bonjour', 'de' => 'Hallo'], 'es'));
        $this->assertSame('', $translator->resolveField([], 'es'));
    }

    public function test_invalidate_forgets_cached_translations_for_language(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola']);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('tenant-1', 'es')
            ->andReturn(['welcome' => 'Hola 2']);

        $translator = $this->translator($tenantRepo);

        $this->assertSame('Hola', $translator->translate('welcome', 'es'));
        $translator->invalidate('tenant-1', 'es');
        $this->assertSame('Hola 2', $translator->translate('welcome', 'es'));
    }

    public function test_translate_falls_back_to_catalog_default_when_no_tenant_override(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $catalog = new InMemorySystemTranslationCatalog();
        $catalog->register(new SystemTranslationEntry(
            key: 'commands.reset.response',
            group: 'commands',
            description: 'reset ack',
            defaults: ['en' => 'Conversation reset.', 'ru' => 'Диалог сброшен.'],
        ));

        $translator = $this->translator($tenantRepo, baseLanguage: 'en', catalog: $catalog);

        $this->assertSame('Диалог сброшен.', $translator->translate('commands.reset.response', 'ru'));
        $this->assertSame('Conversation reset.', $translator->translate('commands.reset.response', 'en'));
        // Language with no catalog entry falls back to the catalog's en default.
        $this->assertSame('Conversation reset.', $translator->translate('commands.reset.response', 'fr'));
    }

    public function test_tenant_override_wins_over_catalog_default(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->with('tenant-1', 'ru')
            ->andReturn(['commands.reset.response' => 'Tenant override RU']);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $catalog = new InMemorySystemTranslationCatalog();
        $catalog->register(new SystemTranslationEntry(
            key: 'commands.reset.response',
            group: 'commands',
            description: 'reset ack',
            defaults: ['en' => 'Conversation reset.', 'ru' => 'Диалог сброшен.'],
        ));

        $translator = $this->translator($tenantRepo, baseLanguage: 'en', catalog: $catalog);

        $this->assertSame('Tenant override RU', $translator->translate('commands.reset.response', 'ru'));
    }

    public function test_assistant_override_wins_over_tenant_override(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->with('tenant-1', 'ru')
            ->andReturn(['commands.reset.response' => 'Tenant override']);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $assistantRepo = Mockery::mock(AssistantTranslationRepositoryInterface::class);
        $assistantRepo->shouldReceive('getAllForLanguage')
            ->with('assistant-1', 'ru')
            ->andReturn(['commands.reset.response' => 'Assistant override']);
        $assistantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $translator = $this->translator(
            $tenantRepo,
            assistantRepo: $assistantRepo,
            assistantId: 'assistant-1',
        );

        $this->assertSame('Assistant override', $translator->translate('commands.reset.response', 'ru'));
    }

    public function test_assistant_layer_skipped_when_no_assistant_resolved(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')
            ->with('tenant-1', 'ru')
            ->andReturn(['key.x' => 'tenant value']);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $assistantRepo = Mockery::mock(AssistantTranslationRepositoryInterface::class);
        $assistantRepo->shouldNotReceive('getAllForLanguage');

        $translator = $this->translator(
            $tenantRepo,
            assistantRepo: $assistantRepo,
            assistantId: null,
        );

        $this->assertSame('tenant value', $translator->translate('key.x', 'ru'));
    }

    public function test_invalidate_assistant_clears_only_assistant_cache(): void
    {
        $tenantRepo = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $tenantRepo->shouldReceive('getAllForLanguage')->andReturn([]);

        $assistantRepo = Mockery::mock(AssistantTranslationRepositoryInterface::class);
        $assistantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('assistant-1', 'ru')
            ->andReturn(['k' => 'v1']);
        $assistantRepo->shouldReceive('getAllForLanguage')
            ->once()
            ->with('assistant-1', 'ru')
            ->andReturn(['k' => 'v2']);

        $translator = $this->translator(
            $tenantRepo,
            assistantRepo: $assistantRepo,
            assistantId: 'assistant-1',
        );

        $this->assertSame('v1', $translator->translate('k', 'ru'));
        $translator->invalidateAssistant('assistant-1', 'ru');
        $this->assertSame('v2', $translator->translate('k', 'ru'));
    }

    private function translator(
        TenantTranslationRepositoryInterface $tenantRepo,
        string $baseLanguage = 'en',
        ?InMemorySystemTranslationCatalog $catalog = null,
        ?AssistantTranslationRepositoryInterface $assistantRepo = null,
        ?string $assistantId = null,
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

        $assistantContext = Mockery::mock(CurrentAssistantInterface::class);

        if (null === $assistantId) {
            $assistantContext->shouldReceive('isResolved')->andReturn(false);
        } else {
            $assistantStub = new Assistant();
            $assistantStub->setRawAttributes(['id' => $assistantId], true);
            $assistantStub->exists = true;
            $assistantContext->shouldReceive('isResolved')->andReturn(true);
            $assistantContext->shouldReceive('get')->andReturn($assistantStub);
        }

        return new CachedContentTranslator(
            $tenantRepo,
            $assistantRepo ?? Mockery::mock(AssistantTranslationRepositoryInterface::class),
            $settings,
            $cache,
            $tenantContext,
            $assistantContext,
            $catalog ?? new InMemorySystemTranslationCatalog(),
        );
    }
}
