<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Signs a staff user in for the Inertia console with the behaviour of Filament's login page, so the
 * two stacks are interchangeable behind the `ui.inertia` switch:
 *
 * - at most {@see self::MAX_ATTEMPTS} attempts a minute per client (every attempt counts, successful
 *   or not), answered with Filament's throttled text;
 * - one failure for an unknown email, a wrong password and an account that may not use the console
 *   (pending, suspended, deactivated), padded to the same duration by a {@see Timebox} so response time
 *   does not tell them apart;
 * - the `Attempting`, `Failed` and `Login` events (the SaaS shell listens to `Login`).
 *
 * The messages reuse Filament's `filament-panels::auth/pages/login` keys; they move to the project's own
 * language files when Filament leaves. Multi-factor authentication is not supported (none is configured).
 */
final readonly class ConsoleLoginService
{
    public const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(
        private AuthFactory $auth,
        private Timebox $timebox,
        private RateLimiter $limiter,
        private Dispatcher $events,
        private Translator $translator,
    ) {
    }

    /**
     * @param  string  $throttleKey  Identifies the client (the IP address).
     *
     * @throws ValidationException With the message on `email`.
     */
    public function attempt(string $email, #[SensitiveParameter] string $password, bool $remember, string $throttleKey): User
    {
        $key = 'console-login:' . $throttleKey;

        if ($this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => $this->throttledMessage($this->limiter->availableIn($key))]);
        }

        $this->limiter->hit($key, self::DECAY_SECONDS);

        $guard = $this->guard();

        $credentials = ['email' => $email, 'password' => $password];

        $user = $this->timebox->call(function (Timebox $timebox) use ($guard, $credentials, $remember): User {
            $this->events->dispatch(new Attempting($guard->getName(), $credentials, $remember));

            $provider = $guard->getProvider();
            $user     = $provider->retrieveByCredentials($credentials);

            if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials) || ! $user->canAccessConsole()) {
                $this->events->dispatch(new Failed($guard->getName(), $user, $credentials));

                $this->fail();
            }

            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        // Validated once more by the guard: it fires `Login` and sets the remember cookie.
        if (! $guard->attemptWhen($credentials, static fn (Authenticatable $candidate): bool => $candidate instanceof User && $candidate->canAccessConsole(), $remember)) {
            $this->fail();
        }

        return $user;
    }

    private function guard(): SessionGuard
    {
        $guard = $this->auth->guard();

        assert($guard instanceof SessionGuard);

        return $guard;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'email' => $this->translator->get('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    private function throttledMessage(int $seconds): string
    {
        $replace = ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)];

        $title = $this->translator->get('filament-panels::auth/pages/login.notifications.throttled.title', $replace);
        $body  = is_array($throttled = $this->translator->get('filament-panels::auth/pages/login.notifications.throttled')) && array_key_exists('body', $throttled)
            ? $this->translator->get('filament-panels::auth/pages/login.notifications.throttled.body', $replace)
            : null;

        return null === $body ? $title : $title . '. ' . $body;
    }
}
