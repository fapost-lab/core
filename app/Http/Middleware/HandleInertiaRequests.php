<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Services\CurrentAccessState;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'locale'     => app()->getLocale(),
            // Page translations by namespace, never whole files of other pages.
            'translations' => [
                'builder' => trans('builder'),
                'auth'    => trans('auth'),
            ],
            'accessState' => $this->accessState(),
            // Everything below is a closure: this middleware runs in the `web` group, before the route middleware that
            // resolves the tenant's assistant, and on a page without a tenant nobody can be asked for.
            'auth' => [
                'user'        => fn (): ?array => $this->user($request),
                'permissions' => fn (): array => $this->permissions($request),
            ],
            'flash' => fn (): array => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                'error'   => $request->hasSession() ? $request->session()->get('error') : null,
            ],
            'broadcaster'   => fn (): array => $this->broadcaster(),
            'supportAccess' => fn (): ?array => $this->supportAccess($request),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, isAdmin: bool}|null
     */
    private function user(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id'      => (string) $user->getKey(),
            'name'    => (string) $user->name,
            'email'   => (string) $user->email,
            'isAdmin' => $user->isAdmin(),
        ];
    }

    /**
     * Permission values the user holds, to show or hide controls. The server still authorizes every action.
     *
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * What the browser needs to decide whether live updates are possible. `null` is Laravel's "no broadcasting".
     *
     * @return array{enabled: bool, name: string}
     */
    private function broadcaster(): array
    {
        $name = (string) config('broadcasting.default', 'null');

        return ['enabled' => ! in_array($name, ['', 'null', 'log'], true), 'name' => $name];
    }

    /**
     * The operator behind a support session, for its banner. Only the platform support user has one.
     *
     * @return array{operatorName: string, operatorEmail: string, expiresAt: string}|null
     */
    private function supportAccess(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformSupport() || ! $request->hasSession()) {
            return null;
        }

        $current = (new SupportAccessSession($request->session()))->current();

        if (null === $current) {
            return null;
        }

        return [
            'operatorName'  => $current['operator_name'],
            'operatorEmail' => $current['operator_email'],
            'expiresAt'     => $current['expires_at'],
        ];
    }

    /**
     * The tenant's access mode and notice for the builder and the UI kit.
     *
     * @return array{mode: string, notice: array{title: string, message: string|null, actionLabel: string|null, actionUrl: string|null}|null}
     */
    private function accessState(): array
    {
        $state  = app(CurrentAccessState::class)->get();
        $notice = $state->notice;

        return [
            'mode'   => $state->mode->value,
            'notice' => null === $notice ? null : [
                'title'       => $notice->title,
                'message'     => $notice->message,
                'actionLabel' => $notice->actionLabel,
                'actionUrl'   => $notice->actionUrl,
            ],
        ];
    }
}
