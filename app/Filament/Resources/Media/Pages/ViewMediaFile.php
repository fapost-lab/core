<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Preview\MediaPreviewRegistry;
use App\Filament\Resources\Media\MediaResource;
use Filament\Resources\Pages\Page;

/**
 * Read-only file detail page that renders a content-aware preview through the
 * {@see MediaPreviewRegistry}. Falls back to a generic icon + download view when no
 * preview type is registered for the file's extension.
 */
final class ViewMediaFile extends Page
{
    public ?string          $record   = null;
    protected static string $resource = MediaResource::class;
    protected string        $view     = 'filament.resources.media.view-media-file';

    public function mount(string $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string
    {
        return $this->resolveRecord()->name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $registry = app(MediaPreviewRegistry::class);
        $service  = app(MediaServiceInterface::class);

        $file        = $this->resolveRecord();
        $previewType = $registry->resolve($file);

        return [
            'file'        => $file,
            'previewView' => $previewType?->view ?? 'media.preview.fallback',
            'signedUrl'   => $service->signedUrl($file),
            'mimeType'    => $file->blob?->mime_type,
            'sizeBytes'   => (int)($file->blob?->size ?? 0),
            'kind'        => $file->kind->value,
            'iconHero'    => $previewType?->iconHeroicon ?? 'document',
        ];
    }

    private function resolveRecord(): \App\Domains\Media\Models\MediaFile
    {
        $service = app(MediaServiceInterface::class);
        $file    = $service->find((string)$this->record);

        if (null === $file) {
            abort(404);
        }

        return $file->loadMissing('blob');
    }
}
