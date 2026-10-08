<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
            'csrf_token'   => csrf_token(),
            'locale'       => app()->getLocale(),
            'translations' => [
                'builder' => trans('builder'),
            ],
            'accessState' => $this->accessState(),
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
