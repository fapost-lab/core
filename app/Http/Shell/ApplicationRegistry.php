<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;

/**
 * The applications of the shell's rail a user may open, in a fixed order, and the menu of each.
 *
 * Today Core's two: the Console, which works per assistant, and the Admin panel, tenant-wide. Their visibility is the
 * panel access of today: both need an account that may use the console ({@see User::canAccessConsole()}), and the
 * Console needs an assistant the user may open ({@see AccessibleAssistants}, the list of the assistant switcher and of
 * Filament's tenant menu). This is the seam the Solution extension contract will feed; it is internal until then.
 *
 * @phpstan-import-type Group from NavigationBuilder
 */
final readonly class ApplicationRegistry
{
    public const string CONSOLE = 'console';

    public const string ADMIN = 'admin';

    /**
     * The session key of the assistant the user last worked in, so the Console opens there again from the Admin panel.
     */
    public const string LAST_ASSISTANT = 'shell.console_assistant';

    private const string CONSOLE_ROUTE = 'filament.assistant.pages.dashboard';

    private const string ADMIN_ROUTE = 'filament.admin.pages.dashboard';

    public function __construct(
        private AccessibleAssistants $assistants,
        private RouteOwnership $ownership,
        private NavigationBuilder $navigation,
    ) {
    }

    /**
     * The applications the user may open. The Console opens on the current assistant when there is one, otherwise on
     * the one the user last worked in when they may still open it, otherwise on the first by name.
     *
     * @return list<ShellApplication>
     */
    public function for(User $user, ?Assistant $current = null, ?string $lastAssistantId = null): array
    {
        if (! $user->canAccessConsole()) {
            return [];
        }

        $applications = [];
        $assistant    = $current ?? $this->consoleAssistant($user, $lastAssistantId);

        if (null !== $assistant) {
            $applications[] = $this->application(self::CONSOLE, 'message-square', self::CONSOLE_ROUTE, ['tenant' => $assistant->getKey()], $assistant);
        }

        $applications[] = $this->application(self::ADMIN, 'shield', self::ADMIN_ROUTE, []);

        return $applications;
    }

    /**
     * The menu of an application, without badges (see {@see NavigationBuilder::console()}).
     *
     * @return list<Group>
     */
    public function menu(User $user, ShellApplication $application): array
    {
        return null === $application->assistant
            ? $this->navigation->admin($user)
            : $this->navigation->console($user, $application->assistant, false);
    }

    private function consoleAssistant(User $user, ?string $lastAssistantId): ?Assistant
    {
        $assistants = $this->assistants->for($user);

        return $assistants->first(static fn (Assistant $assistant): bool => (string) $assistant->getKey() === $lastAssistantId)
            ?? $assistants->first();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function application(string $key, string $icon, string $route, array $parameters, ?Assistant $assistant = null): ShellApplication
    {
        return new ShellApplication(
            key: $key,
            label: __("console.applications.apps.{$key}.label"),
            short: __("console.applications.apps.{$key}.short"),
            scope: __("console.applications.apps.{$key}.scope"),
            icon: $icon,
            href: route($route, $parameters, false),
            external: ! $this->ownership->isMigrated($route),
            pinnedByDefault: true,
            assistant: $assistant,
        );
    }
}
