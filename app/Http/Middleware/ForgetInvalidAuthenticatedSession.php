<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final readonly class ForgetInvalidAuthenticatedSession
{
    public function __construct(
        private QueueingFactory $cookies,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();

        if ($guard instanceof SessionGuard) {
            $this->forgetInvalidSessionUserId($request, $guard);
            $this->forgetInvalidRememberCookie($request, $guard);
        }

        return $next($request);
    }

    private function forgetInvalidSessionUserId(Request $request, SessionGuard $guard): void
    {
        $sessionKey = $guard->getName();
        $identifier = $request->session()->get($sessionKey);

        if ( ! $this->isValidIdentifier($guard->getProvider(), $identifier)) {
            $request->session()->forget($sessionKey);
        }
    }

    private function forgetInvalidRememberCookie(Request $request, SessionGuard $guard): void
    {
        $recaller = $request->cookies->get($guard->getRecallerName());

        if ( ! is_string($recaller) || '' === $recaller) {
            return;
        }

        [$identifier] = explode('|', $recaller, 2);

        if ( ! $this->isValidIdentifier($guard->getProvider(), $identifier)) {
            $this->cookies->queue($this->cookies->forget($guard->getRecallerName()));
        }
    }

    /**
     * @param  mixed  $identifier
     */
    private function isValidIdentifier(UserProvider $provider, mixed $identifier): bool
    {
        if ( ! is_string($identifier) || '' === $identifier) {
            return false;
        }

        if ( ! $provider instanceof EloquentUserProvider) {
            return true;
        }

        $user = $provider->createModel();

        if ( ! $user instanceof Model) {
            return true;
        }

        if ($user->getIncrementing() || 'string' !== $user->getKeyType()) {
            return true;
        }

        return Str::isUuid($identifier);
    }
}
