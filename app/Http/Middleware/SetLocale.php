<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    private const array SUPPORTED_LOCALES = ['en', 'ru', 'uk'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $lang = $request->query('lang');
        if (is_string($lang) && in_array($lang, self::SUPPORTED_LOCALES, true)) {
            return $lang;
        }

        $sessionLocale = $request->session()->get('locale');
        if (is_string($sessionLocale) && in_array($sessionLocale, self::SUPPORTED_LOCALES, true)) {
            return $sessionLocale;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
        if (null !== $preferred) {
            return $preferred;
        }

        return config('app.locale', 'en');
    }
}
