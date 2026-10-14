<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Staff\Models\User;

/**
 * Which applications a user keeps in the shell's rail and in what order (`users.pinned_applications`), and the rail's
 * shared prop. Until the user changes it the rail holds the applications pinned by default, in the registry's order.
 */
final readonly class ApplicationRail
{
    /**
     * What the shell draws: every application the user may open, the pinned keys in the user's order, the one the
     * page belongs to, and where a new order is sent.
     *
     * @param  list<ShellApplication>  $applications
     *
     * @return array{current: string|null, items: list<array<string, mixed>>, rail: list<string>, railUrl: string}
     */
    public function props(User $user, array $applications, string $current): array
    {
        $keys = array_map(static fn (ShellApplication $application): string => $application->key, $applications);

        return [
            'current' => in_array($current, $keys, true) ? $current : null,
            'items'   => array_map(static fn (ShellApplication $application): array => $application->toArray(), $applications),
            'rail'    => $this->pinned($user, $applications),
            'railUrl' => route('console.applications.rail.update', [], false),
        ];
    }

    /**
     * The pinned keys the user may open, in the user's order.
     *
     * @param  list<ShellApplication>  $applications
     *
     * @return list<string>
     */
    public function pinned(User $user, array $applications): array
    {
        $stored = $user->pinned_applications;

        if (! is_array($stored)) {
            return array_values(array_map(
                static fn (ShellApplication $application): string => $application->key,
                array_filter($applications, static fn (ShellApplication $application): bool => $application->pinnedByDefault),
            ));
        }

        $visible = array_map(static fn (ShellApplication $application): string => $application->key, $applications);

        return array_values(array_unique(array_filter(
            $stored,
            static fn (mixed $key): bool => is_string($key) && in_array($key, $visible, true),
        )));
    }

    /**
     * Stores the new order of the applications the user sees. A pinned application the user cannot open right now (the
     * Console while no assistant is open to them) keeps its place at the end, so losing access for a while does not
     * unpin it.
     *
     * @param  list<string>  $keys  Distinct keys of applications in `$applications`.
     * @param  list<ShellApplication>  $applications
     */
    public function save(User $user, array $keys, array $applications): void
    {
        $visible = array_map(static fn (ShellApplication $application): string => $application->key, $applications);
        $stored  = is_array($user->pinned_applications) ? $user->pinned_applications : [];
        $hidden  = array_filter($stored, static fn (mixed $key): bool => is_string($key) && ! in_array($key, $visible, true));

        $user->forceFill(['pinned_applications' => array_values(array_unique([...$keys, ...$hidden]))])->saveQuietly();
    }
}
