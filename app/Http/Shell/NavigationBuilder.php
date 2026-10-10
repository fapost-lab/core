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
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * The shell's side menu for the Inertia console, with the groups, order, labels and visibility of the Filament
 * navigation it replaces: a model's `viewAny` policy or the permission the Filament page asks for, so a user sees an
 * item exactly when Filament showed it. Administrators pass every check through `Gate::before`.
 *
 * Items keep Filament's route names. A screen Filament never had is given by its full `console.*` route name, taken
 * as is. An item whose screen has not moved off Filament yet is marked `external` and
 * rendered as a plain link (see {@see RouteOwnership}); an item whose route does not exist is left out.
 *
 * @phpstan-type Item array{key: string, label: string, href: string, icon: string, external: bool, badge: string|null}
 * @phpstan-type Group array{label: string|null, items: list<Item>}
 */
final readonly class NavigationBuilder
{
    public function __construct(
        private RouteOwnership $ownership,
    ) {
    }

    /**
     * The menu of an assistant's screens (`assistant/{tenant}/*`).
     *
     * @return list<Group>
     */
    public function console(User $user, Assistant $assistant): array
    {
        $overview   = __('assistant.navigation.groups.overview');
        $channels   = __('assistant.navigation.groups.channels');
        $flow       = __('assistant.navigation.groups.flow');
        $operations = __('assistant.navigation.groups.operations');
        $settings   = __('assistant.navigation.groups.settings');

        $entries = [
            $this->entry($overview, 'pages.dashboard', __('console.navigation.dashboard'), 'layout-grid', -100, true),
            $this->entry($channels, 'resources.channels.index', __('staff.channels.plural_label'), 'signal', 10, $this->canViewAny($user, Channel::class)),
            $this->entry($flow, 'resources.flows.index', __('assistant.flows.plural_label'), 'arrow-left-right', 40, $this->canViewAny($user, FlowDraft::class)),
            $this->entry($flow, 'resources.flow-groups.index', __('assistant.flow_groups.plural_label'), 'folder-open', 50, $this->canViewAny($user, FlowGroup::class)),
            $this->entry($operations, 'resources.flow-sessions.index', __('assistant.flow_sessions.plural_label'), 'list', 60, $this->canViewAny($user, FlowSession::class)),
            $this->entry($operations, 'resources.flow-logs.index', __('assistant.flow_logs.plural_label'), 'file-text', 70, $this->canViewAny($user, FlowLog::class)),
            $this->entry($operations, 'resources.contacts.index', __('contact.plural_label'), 'circle-user', 70, $this->canViewAny($user, Contact::class)),
            $this->entry($operations, 'resources.contact-groups.index', __('contact_group.plural_label'), 'users-round', 72, $this->canViewAny($user, ContactGroup::class)),
            $this->entry(
                $operations,
                'resources.conversations.index',
                __('conversation.plural_label'),
                'messages-square',
                75,
                $this->canViewAny($user, Conversation::class),
                fn (): ?string => $this->unreadConversations($assistant),
            ),
            $this->entry($operations, 'resources.contact-segments.index', __('segment.plural_label'), 'funnel', 78, $this->canViewAny($user, ContactSegment::class)),
            $this->entry($operations, 'resources.broadcasts.index', __('broadcast.plural_label'), 'megaphone', 80, $this->canViewAny($user, Broadcast::class)),
            $this->entry($settings, 'pages.settings', __('assistant.pages.settings.title'), 'settings', 50, $user->can(Permission::ManageAssistantSettings->value)),
            $this->entry($settings, 'pages.translations', __('staff.tenant_translations.navigation'), 'languages', 70, $user->can(Permission::ManageTranslations->value)),
        ];

        return $this->groups($entries, 'filament.assistant.', ['tenant' => $assistant->getKey()]);
    }

    /**
     * The menu of the tenant-wide screens (`admin/*`).
     *
     * @return list<Group>
     */
    public function admin(User $user): array
    {
        $staff = __('staff.navigation.group');
        $media = __('media.navigation_group');

        $entries = [
            $this->entry(null, 'pages.dashboard', __('console.navigation.dashboard'), 'home', -100, true),
            $this->entry(__('staff.assistants.label'), 'resources.assistants.index', __('staff.assistants.plural_label'), 'rocket', 0, $this->canViewAny($user, Assistant::class)),
            $this->entry($staff, 'resources.users.index', __('staff.users.plural_label'), 'users', 0, $this->canViewAny($user, User::class)),
            $this->entry($staff, 'resources.roles.index', __('staff.roles.plural_label'), 'shield-check', 0, $this->canViewAny($user, Role::class)),
            $this->entry($staff, 'console.admin.support-access.index', __('console.support_access.title'), 'life-buoy', 10, $this->canViewAny($user, SupportAccessEntry::class)),
            $this->entry(
                $media,
                'resources.media.index',
                __('media.plural_model_label'),
                'image',
                0,
                $user->can(Permission::ViewMedia->value) || $user->can(Permission::ManageMedia->value),
            ),
            $this->entry($media, 'pages.translations', __('staff.tenant_translations.navigation'), 'languages', 60, $user->can(Permission::ManageTranslations->value)),
            $this->entry(null, 'pages.tenant-settings', __('staff.tenant_settings.navigation'), 'sliders-horizontal', 100, $user->can(Permission::ManageSettings->value)),
        ];

        return $this->groups($entries, 'filament.admin.', []);
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
     * keeps groups in the order they are first declared, the group-less one first as Filament shows it.
     *
     * @param  list<array{group: string|null, route: string, label: string, icon: string, sort: int, visible: bool, badge: (Closure(): (string|null))|null}>  $entries
     * @param  array<string, mixed>  $parameters
     *
     * @return list<Group>
     */
    private function groups(array $entries, string $prefix, array $parameters): array
    {
        $visible = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['visible'] && $this->ownership->exists($this->routeName($prefix, $entry['route'])),
        ));

        /** @var array<string, list<array{sort: int, item: Item}>> $sorted */
        $sorted = [];
        $labels = [];

        foreach ($visible as $entry) {
            $name = $this->routeName($prefix, $entry['route']);
            $key  = $entry['group'] ?? '';

            $labels[$key]   = $entry['group'];
            $sorted[$key][] = [
                'sort' => $entry['sort'],
                'item' => [
                    'key'      => $name,
                    'label'    => $entry['label'],
                    'href'     => route($name, $parameters, false),
                    'icon'     => $entry['icon'],
                    'external' => ! $this->ownership->isMigrated($name),
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
     * The full route name of an item: a Filament route under the panel's prefix, or a console route as given.
     */
    private function routeName(string $prefix, string $route): string
    {
        return str_starts_with($route, 'console.') ? $route : $prefix . $route;
    }

    /**
     * @param  class-string  $model
     */
    private function canViewAny(User $user, string $model): bool
    {
        return Gate::forUser($user)->allows('viewAny', $model);
    }

    /**
     * Threads with unread messages for the assistant, as the Filament resource counts them.
     */
    private function unreadConversations(Assistant $assistant): ?string
    {
        $count = Conversation::query()
            ->where('assistant_id', $assistant->getKey())
            ->where('unread_count', '>', 0)
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
