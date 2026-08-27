<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Contact\Jobs\SendContactNotificationJob;
use App\Domains\Flow\Enums\ContactNotifyTarget;
use App\Domains\Flow\Enums\NotifyMode;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Enums\StaffNotifyTarget;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\ArrayField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * `notify` node — sends a notification from a flow, in one of two modes:
 *
 *  - **Staff** — escalates to admin-panel users (in-app / email), admin-UI
 *    language. Recipients: all assistant staff / role / explicit users.
 *  - **Contacts** — reaches bot end-users via an assistant, content language.
 *    Recipients: by tag / all contacts of the assistant.
 *
 * Both deliveries are live and queued: staff onto `messaging.system`, contacts
 * onto `messaging.broadcast` (fanned out per recipient by
 * {@see SendContactNotificationJob}). Both modes expose the single `default`
 * output.
 *
 * Idempotency: each mode writes a per-node state marker and the dispatched job
 * carries a Redis guard, so a re-executed node / retried job never double-sends.
 */
final class NotifyNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'notify';

    /** Channel pseudo-value expanding to every registered staff transport. */
    private const string CHANNEL_ALL = 'all';

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly TemplateRenderer $templates,
        private readonly StaffNotifierRegistry $notifiers,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Contact';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        $staffMode    = ['mode' => NotifyMode::Staff->value];
        $contactsMode = ['mode' => NotifyMode::Contacts->value];

        return Schema::make()
            ->required(['mode'])
            ->section(
                Section::make('mode', (string) __('builder.nodes.notify.section_mode'))
                    ->icon('bell-alert')
                    ->fields([
                        SelectField::make('mode')
                            ->label((string) __('builder.nodes.notify.mode'))
                            ->required()
                            ->default(NotifyMode::Staff->value)
                            ->options(NotifyMode::options()),
                    ]),
            )
            ->section(
                Section::make('staff', (string) __('builder.nodes.notify.section_staff'))
                    ->icon('users')
                    ->fields([
                        SelectField::make('target')
                            ->label((string) __('builder.nodes.notify.target'))
                            ->default(StaffNotifyTarget::Assistant->value)
                            ->options(StaffNotifyTarget::options())
                            ->visibleWhen($staffMode),
                        SelectField::make('role')
                            ->label((string) __('builder.nodes.notify.role'))
                            ->options($this->roleOptions())
                            ->visibleWhen(['mode' => NotifyMode::Staff->value, 'target' => StaffNotifyTarget::Role->value]),
                        ArrayField::make('user_ids')
                            ->label((string) __('builder.nodes.notify.user_ids'))
                            ->visibleWhen(['mode' => NotifyMode::Staff->value, 'target' => StaffNotifyTarget::Users->value]),
                        SelectField::make('channel')
                            ->label((string) __('builder.nodes.notify.channel'))
                            ->default(StaffNotifyChannel::InApp->value)
                            ->options($this->channelOptions())
                            ->visibleWhen($staffMode),
                    ]),
            )
            ->section(
                Section::make('contacts', (string) __('builder.nodes.notify.section_contacts'))
                    ->icon('user-group')
                    ->fields([
                        SelectField::make('assistant_id')
                            ->label((string) __('builder.nodes.notify.assistant'))
                            ->visibleWhen($contactsMode),
                        SelectField::make('contact_target')
                            ->label((string) __('builder.nodes.notify.contact_target'))
                            ->default(ContactNotifyTarget::Tag->value)
                            ->options(ContactNotifyTarget::options())
                            ->visibleWhen($contactsMode),
                        ArrayField::make('tags')
                            ->label((string) __('builder.nodes.notify.tags'))
                            ->visibleWhen(['mode' => NotifyMode::Contacts->value, 'contact_target' => ContactNotifyTarget::Tag->value]),
                    ]),
            )
            ->section(
                Section::make('message', (string) __('builder.nodes.notify.section_message'))
                    ->icon('chat-bubble-left-ellipsis')
                    ->fields([
                        TextareaField::make('message')
                            ->label((string) __('builder.nodes.notify.message'))
                            ->required()
                            ->help((string) __('builder.nodes.notify.message_help')),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $mode   = NotifyMode::tryFrom(is_string($config['mode'] ?? null) ? $config['mode'] : '') ?? NotifyMode::Staff;

        return match ($mode) {
            NotifyMode::Staff    => $this->executeStaff($config, $state, $context),
            NotifyMode::Contacts => $this->executeContacts($config, $state, $context),
        };
    }

    /**
     * Staff escalation: render the (admin-language) message and queue delivery.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeStaff(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        // Idempotency: skip dispatch if this node already queued a notification.
        $markerKey = SystemStateKeys::STAFF_NOTIFIED_PREFIX . ".{$context->nodeId}";

        if (true === data_get($state, $markerKey)) {
            return NodeExecutionResult::executed();
        }

        $message = $this->templates->render($config['message'] ?? null, $context, $state);
        $message = is_string($message) ? mb_trim($message) : '';

        if ('' === $message) {
            throw new InvalidNodeConfigException('notify: message is required.');
        }

        $this->dispatcher->dispatch(new SendStaffNotificationJob(
            tenantId: $context->tenantId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            targetConfig: $this->staffTargetConfig($config),
            channels: $this->resolveChannels($config),
            message: $message,
        ));

        return NodeExecutionResult::executed(
            stateChanges: [$markerKey => true],
            metadata: ['mode' => NotifyMode::Staff->value, 'target' => $config['target'] ?? StaffNotifyTarget::Assistant->value],
        );
    }

    /**
     * Contacts mode — compose the (content-language) message from the running
     * flow and queue a fan-out job that reaches the selected assistant's
     * audience (by tag / all). Delivery itself happens off the broadcast queue.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeContacts(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        // Idempotency: skip dispatch if this node already queued a fan-out.
        $markerKey = SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . ".{$context->nodeId}";

        if (true === data_get($state, $markerKey)) {
            return NodeExecutionResult::executed();
        }

        $assistantId = is_string($config['assistant_id'] ?? null) ? mb_trim($config['assistant_id']) : '';

        if ('' === $assistantId) {
            throw new InvalidNodeConfigException('notify: an assistant is required for contacts mode.');
        }

        // Render against the triggering session so {{flow.*}} placeholders resolve;
        // recurses into a localized map, leaving per-recipient language to the job.
        $message = $this->templates->render($config['message'] ?? null, $context, $state);

        if (! $this->hasRenderableMessage($message)) {
            throw new InvalidNodeConfigException('notify: message is required.');
        }

        $target = is_string($config['contact_target'] ?? null) ? $config['contact_target'] : ContactNotifyTarget::Tag->value;
        $tags   = is_array($config['tags'] ?? null)
            ? array_values(array_filter($config['tags'], 'is_string'))
            : [];

        $this->dispatcher->dispatch(new SendContactNotificationJob(
            tenantId: $context->tenantId,
            assistantId: $assistantId,
            contactTarget: $target,
            tags: $tags,
            message: is_array($message) ? $message : (string) $message,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
        ));

        return NodeExecutionResult::executed(
            stateChanges: [$markerKey => true],
            metadata: [
                'mode'           => NotifyMode::Contacts->value,
                'queued'         => true,
                'assistant_id'   => $assistantId,
                'contact_target' => $target,
                'tags'           => $tags,
            ],
        );
    }

    /**
     * Whether a rendered message field carries deliverable text — a non-blank
     * string, or a localized map with at least one non-blank entry.
     */
    private function hasRenderableMessage(mixed $message): bool
    {
        if (is_array($message)) {
            foreach ($message as $value) {
                if (is_string($value) && '' !== mb_trim($value)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($message) && '' !== mb_trim($message);
    }

    /**
     * Extract only the recipient-selection keys forwarded to the staff job.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function staffTargetConfig(array $config): array
    {
        return [
            'target'   => $config['target'] ?? StaffNotifyTarget::Assistant->value,
            'role'     => $config['role'] ?? null,
            'user_ids' => $config['user_ids'] ?? [],
        ];
    }

    /**
     * Resolve the selected staff channel into concrete transport ids. The `all`
     * pseudo-channel expands to every registered transport; an empty/unknown
     * selection falls back to in-app.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveChannels(array $config): array
    {
        $channel = is_string($config['channel'] ?? null) ? $config['channel'] : StaffNotifyChannel::InApp->value;

        if (self::CHANNEL_ALL === $channel) {
            $channels = $this->notifiers->channels();

            return [] === $channels ? [StaffNotifyChannel::InApp->value] : $channels;
        }

        return [$channel];
    }

    /**
     * @return array<string, string>
     */
    private function channelOptions(): array
    {
        return StaffNotifyChannel::options()
            + [self::CHANNEL_ALL => (string) __('builder.nodes.notify.channels.all')];
    }

    /**
     * Builder dropdown for the role target. Keyed by role name so the value
     * round-trips straight into the spatie `role()` scope.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $options = [];

        foreach (RoleEnum::cases() as $role) {
            $options[$role->value] = ucfirst(str_replace('_', ' ', $role->value));
        }

        return $options;
    }
}
