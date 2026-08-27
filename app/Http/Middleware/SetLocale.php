<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and persists the admin/UI interface locale for the whole web surface
 * (Filament panels + Vue builder).
 *
 * Storage is the source of truth: the locale is auto-detected from the browser
 * only on the very first visit, then written to both the session and a long-lived
 * cookie. Every later request reads it back from storage. This prevents the locale
 * from silently "jumping" to the browser default when the session is empty
 * (fresh/expired session, post-login redirect, session regeneration).
 *
 * The cookie name matches the Filament language switcher plugin, so the in-panel
 * switcher and the builder switcher (?lang=) share a single persisted value.
 */
final class SetLocale
{
    /** @var list<string> */
    private const array SUPPORTED_LOCALES = ['en', 'ru', 'uk'];

    private const string LOCALE_COOKIE = 'filament_language_switcher_locale';

    private const int COOKIE_MINUTES = 365 * 24 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);

        if ($request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        // Long-lived cookie keeps the choice sticky across session loss — the key
        // that stops the locale from reverting to the browser default on its own.
        Cookie::queue(self::LOCALE_COOKIE, $locale, self::COOKIE_MINUTES);

        return $next($request);
    }

    /**
     * Resolution order: explicit override → stored (session, then cookie) →
     * first-visit browser detection → platform default.
     */
    private function resolveLocale(Request $request): string
    {
        // 1. Explicit override: ?lang=xx (used by the builder switcher and deep links).
        $lang = $request->query('lang');
        if ($this->isSupported($lang)) {
            return $lang;
        }

        // 2. Stored choice — session.
        if ($request->hasSession()) {
            $sessionLocale = $request->session()->get('locale');
            if ($this->isSupported($sessionLocale)) {
                return $sessionLocale;
            }
        }

        // 3. Stored choice — persistent cookie (survives a fresh or expired session).
        $cookieLocale = $request->cookie(self::LOCALE_COOKIE);
        if ($this->isSupported($cookieLocale)) {
            return $cookieLocale;
        }

        // 4. First visit only: detect from the browser. The result is persisted above,
        //    so detection never runs again once a choice exists.
        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
        if ($this->isSupported($preferred)) {
            return $preferred;
        }

        // 5. Platform default.
        return config('app.locale', 'en');
    }

    private function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true);
    }
}
