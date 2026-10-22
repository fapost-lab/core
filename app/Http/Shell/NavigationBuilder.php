<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The shell's side menu for the Inertia console, with the groups, order, labels and visibility of the Filament
 * navigation it replaced: a model's `viewAny` policy or the permission the Filament page asked for, so a user sees an
 * item exactly when Filament showed it. Administrators pass every check through `Gate::before`.
 *
 * Each item names its full `console.*` route; an item whose route does not exist is left out. `external` (a plain link, a full page load) is false for
 * every Core item; it stays for an item that leads off the console.
 *
 * @phpstan-type Item array{key: string, label: string, href: string, icon: string, external: bool, badge: string|null}
 * @phpstan-type Group array{label: string|null, items: list<Item>}
 */
final readonly class NavigationBuilder
{
    public function __construct(
        private ConversationInbox $inbox,
    ) {
    }

    /**
     * The menu of an assistant's screens (`assistant/{assistant}/*`). Without badges it asks no counts: the search
     * palette lists the pages of an application it is not in, and a count there would be a query nobody sees.
     *
     * @return list<Group>
     */
    public function console(User $user, Assistant $assistant, bool $badges = true): array
    {
        $overview   = __('assistant.navigation.groups.overview');
        $channels   = __('assistant.navigation.groups.channels');
        $flow       = __('assistant.navigation.groups.flow');
        $operations = __('assistant.navigation.groups.operations');
        $settings   = __('assistant.navigation.groups.settings');

        $entries = [
            $this->entry($overview, 'console.dashboard', __('console.navigation.dashboard'), 'layout-grid', -100, true),
            $this->entry($channels, 'console.channels.index', __('staff.channels.plural_label'), 'signal', 10, $this->canViewAny($user, Channel::class)),
            $this->entry($flow, 'console.flows.index', __('assistant.flows.plural_label'), 'arrow-left-right', 40, $this->canViewAny($user, FlowDraft::class)),
            $this->entry($flow, 'console.flow-groups.index', __('assistant.flow_groups.plural_label'), 'folder-open', 50, $this->canViewAny($user, FlowGroup::class)),
            $this->entry($operations, 'console.flow-sessions.index', __('assistant.flow_sessions.plural_label'), 'list', 60, $this->canViewAny($user, FlowSession::class)),
            $this->entry($operations, 'console.flow-logs.index', __('assistant.flow_logs.plural_label'), 'file-text', 70, $this->canViewAny($user, FlowLog::class)),
            $this->entry($operations, 'console.contacts.index', __('contact.plural_label'), 'circle-user', 70, $this->canViewAny($user, Contact::class)),
            $this->entry($operations, 'console.contact-groups.index', __('contact_group.plural_label'), 'users-round', 72, $this->canViewAny($user, ContactGroup::class)),
            $this->entry(
                $operations,
                'console.conversations.index',
                __('conversation.plural_label'),
                'messages-square',
                75,
                $this->canViewAny($user, Conversation::class),
                $badges ? fn (): ?string => $this->unreadConversations($assistant) : null,
            ),
            $this->entry($operations, 'console.contact-segments.index', __('segment.plural_label'), 'funnel', 78, $this->canViewAny($user, ContactSegment::class)),
            $this->entry($operations, 'console.broadcasts.index', __('broadcast.plural_label'), 'megaphone', 80, $this->canViewAny($user, Broadcast::class)),
            $this->entry($settings, 'console.settings.edit', __('assistant.pages.settings.title'), 'settings', 50, $user->can(Permission::ManageAssistantSettings->value)),
            $this->entry($settings, 'console.translations.index', __('staff.tenant_translations.navigation'), 'languages', 70, $user->can(Permission::ManageTranslations->value)),
        ];

        return $this->groups($entries, ['assistant' => $assistant->getKey()]);
    }

    /**
     * The menu of the tenant-wide screens (`admin/*`).
     *
     * @return list<Group>
     */
    public function admin(User $user): array
    {
        $staff  = __('staff.navigation.group');
        $media  = __('media.navigation_group');
        $system = __('console.navigation.system');

        $entries = [
            $this->entry(null, 'console.admin.dashboard', __('console.navigation.dashboard'), 'home', -100, true),
            $this->entry(__('staff.assistants.label'), 'console.admin.assistants.index', __('staff.assistants.plural_label'), 'rocket', 0, $this->canViewAny($user, Assistant::class)),
            $this->entry($staff, 'console.admin.users.index', __('staff.users.plural_label'), 'users', 0, $this->canViewAny($user, User::class)),
            $this->entry($staff, 'console.admin.roles.index', __('staff.roles.plural_label'), 'shield-check', 0, $this->canViewAny($user, Role::class)),
            $this->entry($staff, 'console.admin.support-access.index', __('console.support_access.title'), 'life-buoy', 10, $this->canViewAny($user, SupportAccessEntry::class)),
            $this->entry(
                $media,
                'console.admin.media.index',
                __('media.plural_model_label'),
                'image',
                0,
                $user->can(Permission::ViewMedia->value) || $user->can(Permission::ManageMedia->value),
            ),
            $this->entry($media, 'console.admin.translations.index', __('staff.tenant_translations.navigation'), 'languages', 60, $user->can(Permission::ManageTranslations->value)),
            $this->entry($system, 'console.admin.tenant-settings.edit', __('staff.tenant_settings.navigation'), 'sliders-horizontal', 100, $user->can(Permission::ManageSettings->value)),
            $this->entry(
                $system,
                'console.admin.health.index',
                __('console.health.navigation'),
                'heart-pulse',
                80,
                TenancyResolutionMode::Single === TenancyResolutionMode::fromConfig() && $user->can(Permission::ViewSystem->value),
            ),
            $this->entry($system, 'console.admin.usage.index', __('console.usage.navigation'), 'gauge', 90, $user->can(Permission::ManageSettings->value)),
            $this->entry($system, 'console.admin.solutions.index', __('console.solutions.navigation'), 'puzzle', 95, $user->can(Permission::ManageSettings->value)),
            $this->entry(
                $system,
                'console.admin.platform-settings.edit',
                __('console.platform_settings.navigation'),
                'settings',
                110,
                TenancyResolutionMode::Single === TenancyResolutionMode::fromConfig() && $user->isAdmin() && ! $user->isPlatformSupport(),
            ),
        ];

        return $this->groups($entries, []);
    }

    /**
     * @param  (Closure(): (string|null))|null  $badge
     *
     * @return array{group: string|null, route: string, label: string, icon: string, sort: int, visible: bool, badge: (Closure(): (string|null))|null}
     */
    private function entry(?string $group, string $route, string $label, string $icon, int $sort, bool $visible, ?Closure $badge = null): array
    {
        return ['group' => $group, 'route' => $route, 'label' => $label, 'icon' => $icon, 'sort' => $sort, 'visible' => $visible, 'badge' => $badge];
    }

    /**
     * Keeps what the user may see, orders items by their sort within a group (declaration order breaks ties) and
     * keeps groups in the order they are first declared, the group-less one first as Filament showed it.
     *
     * @param  list<array{group: string|null, route: string, label: string, icon: string, sort: int, visible: bool, badge: (Closure(): (string|null))|null}>  $entries
     * @param  array<string, mixed>  $parameters
     *
     * @return list<Group>
     */
    private function groups(array $entries, array $parameters): array
    {
        $visible = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['visible'] && Route::has($entry['route']),
        ));

        /** @var array<string, list<array{sort: int, item: Item}>> $sorted */
        $sorted = [];
        $labels = [];

        foreach ($visible as $entry) {
            $name = $entry['route'];
            $key  = $entry['group'] ?? '';

            $labels[$key]   = $entry['group'];
            $sorted[$key][] = [
                'sort' => $entry['sort'],
                'item' => [
                    'key'      => $name,
                    'label'    => $entry['label'],
                    'href'     => route($name, $parameters, false),
                    'icon'     => $entry['icon'],
                    'external' => false,
                    'badge'    => null === $entry['badge'] ? null : ($entry['badge'])(),
                ],
            ];
        }

        $result = [];

        foreach ($sorted as $key => $rows) {
            usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $result[] = ['label' => $labels[$key], 'items' => array_map(static fn (array $row): array => $row['item'], $rows)];
        }

        usort($result, static fn (array $a, array $b): int => (null === $a['label'] ? 0 : 1) <=> (null === $b['label'] ? 0 : 1));

        return $result;
    }

    /**
     * @param  class-string  $model
     */
    private function canViewAny(User $user, string $model): bool
    {
        return Gate::forUser($user)->allows('viewAny', $model);
    }

    /**
     * Threads with unread messages for the assistant, counted by the inbox over the query its list uses.
     */
    private function unreadConversations(Assistant $assistant): ?string
    {
        $count = $this->inbox->unreadCount($assistant);

        return $count > 0 ? (string) $count : null;
    }
}
