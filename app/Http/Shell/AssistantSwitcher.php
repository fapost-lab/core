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
        private NavigationBuilder $navigation,
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
        return [
            'current' => ['id' => (string) $current->getKey(), 'name' => (string) $current->name],
            'items'   => $this->assistants->for($user)
                ->map(function (Assistant $assistant) use ($user): ?array {
                    $entry = $this->navigation->entryRoute($user, $assistant);

                    return null === $entry ? null : [
                        'id'       => (string) $assistant->getKey(),
                        'name'     => (string) $assistant->name,
                        'href'     => route($entry, ['assistant' => $assistant->getKey()], false),
                        'external' => false,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
