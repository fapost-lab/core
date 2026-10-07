<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use Illuminate\Support\Facades\Auth;

/**
 * Registered in each panel as a `PanelsRenderHook::BODY_START` render hook.
 *
 * The strip across the top of a panel that tells an operator, and anyone looking over their
 * shoulder, that the session belongs to platform support.
 */
final class SupportAccessBanner
{
    /**
     * Empty unless the current session is a support session.
     */
    public static function render(): string
    {
        $user    = Auth::user();
        $current = $user instanceof User && $user->isPlatformSupport()
            ? new SupportAccessSession(session()->driver())->current()
            : null;

        if (null === $current) {
            return '';
        }

        return view('filament.support.banner', [
            'name'  => $current['operator_name'],
            'email' => $current['operator_email'],
        ])->render();
    }
}
