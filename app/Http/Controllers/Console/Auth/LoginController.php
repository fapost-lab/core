<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Auth;

use App\Domains\Staff\Services\ConsoleLoginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Sign-in for the Inertia console. A signed-in visitor is sent on to the console instead of seeing the form.
 *
 * Success answers with {@see Inertia::location()}: the intended URL often belongs to Filament, and an
 * ordinary redirect from an Inertia request would load that HTML page inside the Inertia app.
 */
final class LoginController extends Controller
{
    public function show(): Response|SymfonyResponse
    {
        if (Auth::guard()->check()) {
            return Inertia::location($this->home());
        }

        return Inertia::render('Auth/Login', ['action' => route('console.auth.login.attempt')]);
    }

    public function store(Request $request, ConsoleLoginService $login): SymfonyResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login->attempt(
            $credentials['email'],
            $credentials['password'],
            (bool) ($credentials['remember'] ?? false),
            (string) $request->ip(),
        );

        $request->session()->regenerate();

        return Inertia::location((string) $request->session()->pull('url.intended', $this->home()));
    }

    /**
     * The assistant panel's sign-in page: there is one sign-in for the whole console.
     */
    public function assistant(): RedirectResponse
    {
        return redirect()->route('filament.admin.auth.login');
    }

    private function home(): string
    {
        return route('filament.admin.pages.dashboard');
    }
}
