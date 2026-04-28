<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Controllers;

use App\Domains\Staff\Services\ActivateUserService;
use App\Domains\Staff\Services\ActivationTokenService;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ActivationController extends Controller
{
    public function __construct(
        private readonly ActivateUserService $activateUserService,
        private readonly ActivationTokenService $activationTokenService,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $token = (string)$request->query('token', '');
        if ('' === $token) {
            return redirect()->route('landing')->withErrors(['token' => __('Missing activation token.')]);
        }

        $user = $this->activationTokenService->findValidUserByPlainToken($token);

        if (null === $user) {
            return view('staff.activation-expired');
        }

        return view('staff.activate', [
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        try {
            $this->activateUserService->activate($validated['token'], [
                'password'              => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
            ]);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('activate.show', ['token' => $validated['token']])
                ->withErrors($exception->errors());
        }

        return redirect()->to(Filament::getPanel('admin')->getUrl());
    }
}
