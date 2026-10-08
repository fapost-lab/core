<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;

/**
 * What the shell's assistant switcher shows: the assistants the user may open, the current one and the way back to
 * the tenant-wide screens. The list is the one Filament's tenant menu uses ({@see AccessibleAssistants}).
 */
final readonly class AssistantSwitcher
{
    public function __construct(
        private AccessibleAssistants $assistants,
        private RouteOwnership $ownership,
    ) {
    }

    /**
     * @return array{
     *     current: array{id: string, name: string},
     *     items: list<array{id: string, name: string, href: string, external: bool}>,
     *     back: array{href: string, external: bool}
     * }
     */
    public function for(User $user, Assistant $current): array
    {
        $dashboard = 'filament.assistant.pages.dashboard';
        $back      = 'filament.admin.resources.assistants.index';

        return [
            'current' => ['id' => (string) $current->getKey(), 'name' => (string) $current->name],
            'items'   => $this->assistants->for($user)
                ->map(fn (Assistant $assistant): array => [
                    'id'       => (string) $assistant->getKey(),
                    'name'     => (string) $assistant->name,
                    'href'     => route($dashboard, ['tenant' => $assistant->getKey()], false),
                    'external' => ! $this->ownership->isMigrated($dashboard),
                ])
                ->values()
                ->all(),
            'back' => [
                'href'     => route($back, [], false),
                'external' => ! $this->ownership->isMigrated($back),
            ],
        ];
    }
}
