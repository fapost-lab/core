<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Covers interface-locale resolution and persistence: explicit override, stored
 * (session/cookie) precedence over browser detection (the "no jumping" guarantee),
 * first-visit browser detection, and sticky-cookie persistence.
 */
final class InterfaceLocaleTest extends TestCase
{
    private const string COOKIE = 'filament_language_switcher_locale';

    public function test_lang_query_overrides(): void
    {
        $this->runMiddleware(Request::create('/', 'GET', ['lang' => 'ru']));

        $this->assertSame('ru', $this->app->getLocale());
    }

    public function test_stored_cookie_beats_browser_no_jumping(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'en');
        $request->cookies->set(self::COOKIE, 'ru');

        $this->runMiddleware($request);

        $this->assertSame('ru', $this->app->getLocale());
    }

    public function test_stored_session_beats_browser(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'en');
        $session = $this->app['session']->driver('array');
        $session->put('locale', 'uk');
        $request->setLaravelSession($session);

        $this->runMiddleware($request);

        $this->assertSame('uk', $this->app->getLocale());
    }

    public function test_first_visit_detects_browser(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'uk-UA,uk;q=0.9,en;q=0.5');

        $this->runMiddleware($request);

        $this->assertSame('uk', $this->app->getLocale());
    }

    public function test_unsupported_lang_falls_through_to_default(): void
    {
        $request = Request::create('/', 'GET', ['lang' => 'fr']);
        $request->headers->set('Accept-Language', 'en');

        $this->runMiddleware($request);

        $this->assertSame('en', $this->app->getLocale());
    }

    public function test_resolved_locale_is_persisted_to_a_long_lived_cookie(): void
    {
        $this->runMiddleware(Request::create('/', 'GET', ['lang' => 'ru']));

        $queued = collect(Cookie::getQueuedCookies())
            ->firstWhere(fn ($cookie) => self::COOKIE === $cookie->getName());

        $this->assertNotNull($queued, 'Locale cookie should be queued.');
        $this->assertSame('ru', $queued->getValue());
        $this->assertGreaterThan(0, $queued->getExpiresTime(), 'Cookie should be long-lived.');
    }

    private function runMiddleware(Request $request): void
    {
        (new SetLocale())->handle($request, fn (): Response => new Response());
    }
}
