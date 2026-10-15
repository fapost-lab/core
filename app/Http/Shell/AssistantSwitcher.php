<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;

/**
 * What the shell's assistant switcher shows: the assistants the user may open and the current one (the way to the
 * tenant-wide screens is the application rail). The list is {@see AccessibleAssistants}.
 */
final readonly class AssistantSwitcher
{
    public function __construct(
        private AccessibleAssistants $assistants,
    ) {
    }

    /**
     * @return array{
     *     current: array{id: string, name: string},
     *     items: list<array{id: string, name: string, href: string, external: bool}>
     * }
     */
    public function for(User $user, Assistant $current): array
    {
        $dashboard = 'console.dashboard';

        return [
            'current' => ['id' => (string) $current->getKey(), 'name' => (string) $current->name],
            'items'   => $this->assistants->for($user)
                ->map(fn (Assistant $assistant): array => [
                    'id'       => (string) $assistant->getKey(),
                    'name'     => (string) $assistant->name,
                    'href'     => route($dashboard, ['assistant' => $assistant->getKey()], false),
                    'external' => false,
                ])
                ->values()
                ->all(),
        ];
    }
}
