<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-out for the Inertia console. Idempotent: a visitor who is already signed out lands on the sign-in page too.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(route('filament.admin.auth.login'));
    }
}
