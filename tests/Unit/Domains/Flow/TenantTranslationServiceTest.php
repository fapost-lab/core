<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Services\TenantTranslationService;
use Mockery;
use Tests\TestCase;

final class TenantTranslationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_upsert_invalidates_cached_language_map_after_write(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);

        $repository->shouldReceive('upsert')
            ->once()
            ->with('tenant-1', 'welcome', 'es', 'Hola');

        $translator->shouldReceive('invalidate')
            ->once()
            ->with('tenant-1', 'es');

        $service = new TenantTranslationService($repository, $translator);
        $service->upsert('tenant-1', 'welcome', 'es', 'Hola');
        $this->addToAssertionCount(1);
    }

    public function test_delete_invalidates_cached_language_map_after_removing_override(): void
    {
        $repository = Mockery::mock(TenantTranslationRepositoryInterface::class);
        $translator = Mockery::mock(ContentTranslatorInterface::class);

        $repository->shouldReceive('delete')
            ->once()
            ->with('tenant-1', 'welcome', 'es');

        $translator->shouldReceive('invalidate')
            ->once()
            ->with('tenant-1', 'es');

        $service = new TenantTranslationService($repository, $translator);
        $service->delete('tenant-1', 'welcome', 'es');
        $this->addToAssertionCount(1);
    }
}
