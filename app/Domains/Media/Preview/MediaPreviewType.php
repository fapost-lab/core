<?php

declare(strict_types=1);

namespace App\Domains\Media\Preview;

/**
 * Declarative description of how a media file is previewed in admin / picker UI.
 *
 * Produced once per file extension and stored in {@see MediaPreviewRegistry}. Both the
 * Filament admin and the Vue picker consume the same descriptor: Filament renders the
 * Blade `view` directly; the picker reads the metadata over the API and renders an
 * appropriate native element (or falls back to a server-rendered iframe for views that
 * need external assets — see `requiresExternalRender`).
 */
final readonly class MediaPreviewType
{
    /**
     * @param  list<string>  $extensions    Lowercase extensions this type matches.
     * @param  string        $kind          Coarse preview kind ("image", "video", "audio", "document", "other").
     * @param  string        $view          Blade view path (rendered server-side in Filament).
     * @param  string        $iconHeroicon  Heroicon name (without the "heroicon-o-" prefix) used in lists / pickers.
     * @param  list<string>  $assets        External JS/CSS URLs the view needs (e.g. pdf.js).
     * @param  bool          $requiresExternalRender
     *                                      True when the preview cannot be inlined client-side and must be
     *                                      rendered server-side (e.g. PDF viewer behind pdf.js). The Vue picker
     *                                      routes these through an iframe to the Filament view.
     */
    public function __construct(
        public array $extensions,
        public string $kind,
        public string $view,
        public string $iconHeroicon,
        public array $assets = [],
        public bool $requiresExternalRender = false,
    ) {
    }
}
