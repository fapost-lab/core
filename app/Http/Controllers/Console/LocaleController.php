<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the interface language of the signed-in user: the same session value and the same long-lived cookie that
 * {@see SetLocale} reads and the Filament language switcher writes.
 *
 * It changes the user's own preference (also kept as `users.locale` for mail and notifications), not a record about anyone else, so there is no ability to check (listed as an exemption in
 * ConsoleAuthorizationTest). The answer is a full page visit: the shared translations of every screen change with it.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED_LOCALES)],
        ]);

        $request->session()->put('locale', $validated['locale']);

        // Kept on the account too: queued jobs and mail have no session to read the language from.
        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $validated['locale']])->saveQuietly();
        }

        Cookie::queue(SetLocale::LOCALE_COOKIE, $validated['locale'], SetLocale::COOKIE_MINUTES);

        return Inertia::location($this->returnUrl($request));
    }

    /**
     * Back to the page the user came from, when that page is on this host.
     */
    private function returnUrl(Request $request): string
    {
        $previous = (string) url()->previous();

        if (parse_url($previous, PHP_URL_HOST) === $request->getHost()) {
            return $previous;
        }

        return route('filament.admin.pages.dashboard');
    }
}
