<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the query of the OAuth2 consent callback (the code, the state, the provider's error) off the request before
 * anything that writes the URL into the session sees it: `auth` keeps the full URL of a guest as the "intended" one,
 * and the session keeps the URL of every GET as the "previous" one, a refused (403) one included. The query is kept in
 * the request attribute {@see self::ATTRIBUTE} for the controller, and the request goes on with none.
 *
 * Runs ahead of `auth` (the priority list in `bootstrap/app.php`); the session stores the previous URL only after the
 * response, so this is early enough for it too.
 */
final readonly class TakeOAuthCallbackQuery
{
    public const string ATTRIBUTE = 'oauth_callback_query';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $request->query->all());

        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');

        return $next($request);
    }
}
