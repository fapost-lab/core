<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Tenancy\Services\CurrentAccessState;

/**
 * Registered in each panel as a `PanelsRenderHook::BODY_START` render hook, next to
 * {@see SupportAccessBanner}.
 *
 * The strip across the top of a panel that shows the tenant's staff the notice their access mode
 * carries ("Your trial has ended"). It is the only sign of a stopped tenant in Filament, which
 * has no read-only mode.
 */
final class AccessNoticeBanner
{
    /**
     * Empty unless the operator attached a notice to the tenant's access state.
     */
    public static function render(): string
    {
        $state  = app(CurrentAccessState::class)->get();
        $notice = $state->notice;

        if (null === $notice) {
            return '';
        }

        return view('filament.support.access-notice-banner', [
            'stopped'     => $state->isStopped(),
            'title'       => $notice->title,
            'message'     => $notice->message,
            'actionLabel' => $notice->actionLabel,
            'actionUrl'   => $notice->actionUrl,
        ])->render();
    }
}
