<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;
use Illuminate\Support\Str;

/**
 * The applications of the shell's rail a user may open, in a fixed order, and the menu of each.
 *
 * Core's two first: the Console, which works per assistant, and the Admin panel, tenant-wide. Their visibility is the
 * panel access of today: both need an account that may use the console ({@see User::canAccessConsole()}), and the
 * Console needs an assistant the user may open ({@see AccessibleAssistants}, the list of the assistant switcher). Then the applications of the Solutions served to the tenant, by Solution id
 * ({@see SolutionExtensions}); a per-assistant one opens on the Console's assistant. The menu of each is chosen by its
 * key, not by whether it carries an assistant.
 *
 * @phpstan-import-type Group from NavigationBuilder
 */
final class ApplicationRegistry
{
    public const string CONSOLE = 'console';

    public const string ADMIN = 'admin';

    /**
     * The session key of the assistant the user last worked in, so the Console opens there again from the Admin panel.
     */
    public const string LAST_ASSISTANT = 'shell.console_assistant';

    private const string CONSOLE_ROUTE = 'console.dashboard';

    private const string ADMIN_ROUTE = 'console.admin.dashboard';

    /**
     * Assistants this instance already found, by user and wished-for ids.
     *
     * @var array<string, Assistant|null>
     */
    private array $found = [];

    public function __construct(
        private readonly StaffAssistantService $assistants,
        private readonly NavigationBuilder $navigation,
        private readonly SolutionExtensions $solutions,
    ) {
    }

    /**
     * The applications the user may open. The Console opens on the current assistant when there is one, otherwise on
     * the first of the wished-for ones (the assistant of the tab that asks, then the one last worked in) the user may
     * still open, otherwise on the first by name.
     *
     * @param  list<string|null>  $wishedAssistantIds
     *
     * @return list<ShellApplication>
     */
    public function for(User $user, ?Assistant $current = null, array $wishedAssistantIds = []): array
    {
        if (! $user->canAccessConsole()) {
            return [];
        }

        $applications = [];
        $assistant    = $current ?? $this->consoleAssistant($user, $wishedAssistantIds);

        $entry = null === $assistant ? null : $this->navigation->entryRoute($user, $assistant);

        if (null !== $assistant && null !== $entry) {
            $applications[] = $this->application(self::CONSOLE, 'message-square', $entry, ['assistant' => $assistant->getKey()], $assistant);
        }

        $applications[] = $this->application(self::ADMIN, 'shield', self::ADMIN_ROUTE, []);

        return [...$applications, ...$this->solutions->applications($user, $assistant)];
    }

    /**
     * The menu of an application, without badges (see {@see NavigationBuilder::console()}).
     *
     * @return list<Group>
     */
    public function menu(User $user, ShellApplication $application): array
    {
        return match (true) {
            self::ADMIN === $application->key                                       => $this->navigation->admin($user),
            self::CONSOLE === $application->key && null !== $application->assistant => $this->navigation->console($user, $application->assistant, false),
            default                                                                 => $this->solutions->menu($user, $application),
        };
    }

    /**
     * The same rule as {@see AccessibleAssistants} (the `operate` ability: an assignment unless the user is an administrator), as one ability check and bounded queries.
     *
     * @param  list<string|null>  $wishedIds
     */
    private function consoleAssistant(User $user, array $wishedIds): ?Assistant
    {
        $ids = array_values(array_unique(array_filter($wishedIds, static fn (?string $id): bool => null !== $id && Str::isUuid($id))));
        $key = $user->getKey() . '|' . implode(',', $ids);

        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }

        $assistant = null;

        if ([] !== $ids) {
            $wished    = $this->assistants->query($user)->whereKey($ids)->get()->keyBy(static fn (Assistant $candidate): string => (string) $candidate->getKey());
            $assistant = collect($ids)->map(static fn (string $id): ?Assistant => $wished->get($id))->first(static fn (?Assistant $candidate): bool => null !== $candidate);
        }

        $assistant ??= $this->assistants->query($user)->orderBy('name')->first();

        return $this->found[$key] = $assistant;
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
            external: false,
            pinnedByDefault: true,
            assistant: $assistant,
        );
    }
}
