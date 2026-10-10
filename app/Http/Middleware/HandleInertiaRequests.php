<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Http\Shell\AdminSearch;
use App\Http\Shell\AssistantSwitcher;
use App\Http\Shell\NavigationBuilder;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
        $shared = [
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
            'broadcaster'   => fn (): array => $this->broadcaster($request),
            'supportAccess' => fn (): ?array => $this->supportAccess($request),
        ];

        if (! $this->isConsoleRequest($request)) {
            return $shared;
        }

        // The shell's own props; the builder and the other `web` pages never carry them.
        $shared['translations']['console'] = trans('console');
        $shared['shell']                   = fn (): array => $this->shell();
        $shared['navigation']              = fn (): ?array => $this->navigation($request);
        $shared['assistants']              = fn (): ?array => $this->assistants($request);

        return $shared;
    }

    /**
     * Whether the route runs in the console's `admin` or `console` stack (both end in {@see SetConsoleRootView}).
     */
    private function isConsoleRequest(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        return in_array(SetConsoleRootView::class, app('router')->gatherRouteMiddleware($route), true);
    }

    /**
     * Fixed endpoints and choices of the shell.
     *
     * @return array{localeUrl: string, logoutUrl: string, supportLeaveUrl: string, locales: list<string>}
     */
    private function shell(): array
    {
        return [
            'localeUrl'       => route('console.locale.update', [], false),
            'logoutUrl'       => route('console.auth.logout', [], false),
            'supportLeaveUrl' => route('support.leave', [], false),
            'locales'         => SetLocale::SUPPORTED_LOCALES,
        ];
    }

    /**
     * The side menu: an assistant's screens under `assistant/{tenant}`, otherwise the tenant-wide ones.
     *
     * @return array{mode: string, groups: list<array{label: string|null, items: list<array<string, mixed>>}>, searchUrl: string|null}|null
     */
    private function navigation(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $builder    = app(NavigationBuilder::class);
        $assistants = app(CurrentAssistantInterface::class);

        if ($assistants->isResolved()) {
            return ['mode' => 'console', 'groups' => $builder->console($user, $assistants->get()), 'searchUrl' => null];
        }

        // The search palette replaces Filament's global search, which only the admin panel had.
        return [
            'mode'      => 'admin',
            'groups'    => $builder->admin($user),
            'searchUrl' => app(AdminSearch::class)->isAvailableTo($user) ? route('console.admin.search', [], false) : null,
        ];
    }

    /**
     * The assistant switcher; only the assistant screens have one.
     *
     * @return array<string, mixed>|null
     */
    private function assistants(Request $request): ?array
    {
        $user       = $request->user();
        $assistants = app(CurrentAssistantInterface::class);

        if (! $user instanceof User || ! $assistants->isResolved()) {
            return null;
        }

        return app(AssistantSwitcher::class)->for($user, $assistants->get());
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
     * What the browser needs to decide whether live updates are possible, and how to reach the websocket server when
     * they are. `null` is Laravel's "no broadcasting". A guest gets no endpoint: there is no channel it may join, so the
     * sign-in page never opens a socket (signing in is a full page load, which brings the endpoint).
     *
     * @return array{enabled: bool, name: string, client: array<string, mixed>|null}
     */
    private function broadcaster(Request $request): array
    {
        $status = app(BroadcasterStatus::class);

        return [
            'enabled' => $status->enabled(),
            'name'    => $status->name(),
            'client'  => null === $request->user() ? null : $status->client(),
        ];
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
