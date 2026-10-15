<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Controllers;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ActivateUserService;
use App\Domains\Staff\Services\ActivationTokenService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The invitee's activation page: the link from the invitation mail opens it, the invitee sets a password and is signed in.
 *
 * Both pages are guest pages of the console, like its sign-in (`Auth/Login`). The props carry the token, because the
 * form posts it back, and the invitee's email for the password manager; never anything else of the account.
 *
 * Success answers with {@see Inertia::location()}: in `single` mode the dashboard is on the default tenant's host, which
 * may not be the host the link was opened on, and an Inertia request cannot follow a redirect to another origin.
 */
final class ActivationController extends Controller
{
    public function __construct(
        private readonly ActivateUserService $activateUserService,
        private readonly ActivationTokenService $activationTokenService,
    ) {
    }

    public function show(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        if ('' === $token) {
            return $this->expired();
        }

        $user = $this->activationTokenService->findValidUserByPlainToken($token);

        if (null === $user) {
            return $this->expired();
        }

        return Inertia::render('Auth/Activate', [
            'action' => route('activate.store'),
            'token'  => $token,
            'email'  => (string) $user->email,
        ]);
    }

    public function store(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        try {
            $user = $this->activateUserService->activate($validated['token'], [
                'password'              => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
            ]);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('activate.show', ['token' => $validated['token']])
                ->withErrors($exception->errors());
        }

        $this->signIn($request, $user);

        return Inertia::location(route('console.admin.dashboard'));
    }

    /**
     * Signs the invitee in on a session of their own. Whoever was signed in in this browser (an administrator opening
     * the link to try it) is signed out first and their session dropped: it holds their password hash, which
     * `AuthenticateSession` would compare with the invitee's on the next request and sign the invitee out, and their own
     * state (the intended URL, a support session) is not the invitee's.
     */
    private function signIn(Request $request, User $user): void
    {
        $guard = Auth::guard();

        if ($guard->check()) {
            $guard->logout();
        }

        $request->session()->invalidate();

        // Logging in regenerates the session id once more.
        $guard->login($user);
    }

    private function expired(): Response
    {
        return Inertia::render('Auth/ActivationExpired', ['homeUrl' => url('/')]);
    }
}
