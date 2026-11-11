<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

/**
 * The catalogue of what the audit log records: one case per action, its value the stable machine key stored as the
 * entry's `description` (the text a person reads comes from `lang/{locale}/audit.php`, `actions.<key>`).
 *
 * Only what staff do through the console and the builder is here. Jobs, the flow runtime, message delivery and webhooks
 * record nothing: their changes are not a person's action, and they run on the hot path. A key, once released, is never
 * renamed: stored entries keep it.
 */
enum AuditAction: string
{
    case AssistantCreated         = 'assistant.created';
    case AssistantUpdated         = 'assistant.updated';
    case AssistantDeleted         = 'assistant.deleted';
    case AssistantSettingsUpdated = 'assistant.settings_updated';

    case ChannelCreated            = 'channel.created';
    case ChannelUpdated            = 'channel.updated';
    case ChannelDeleted            = 'channel.deleted';
    case ChannelTokenRotated       = 'channel.token_rotated';
    case ChannelWebhookHashRotated = 'channel.webhook_hash_rotated';

    case FlowCreated      = 'flow.created';
    case FlowUpdated      = 'flow.updated';
    case FlowDeleted      = 'flow.deleted';
    case FlowPublished    = 'flow.published';
    case FlowGroupCreated = 'flow_group.created';
    case FlowGroupUpdated = 'flow_group.updated';
    case FlowGroupDeleted = 'flow_group.deleted';

    case BroadcastCreated   = 'broadcast.created';
    case BroadcastUpdated   = 'broadcast.updated';
    case BroadcastDeleted   = 'broadcast.deleted';
    case BroadcastSent      = 'broadcast.sent';
    case BroadcastCancelled = 'broadcast.cancelled';

    case ContactUpdated        = 'contact.updated';
    case ContactDeleted        = 'contact.deleted';
    case ContactGroupCreated   = 'contact_group.created';
    case ContactGroupUpdated   = 'contact_group.updated';
    case ContactGroupDeleted   = 'contact_group.deleted';
    case ContactSegmentCreated = 'contact_segment.created';
    case ContactSegmentUpdated = 'contact_segment.updated';
    case ContactSegmentDeleted = 'contact_segment.deleted';

    case UserInvited          = 'user.invited';
    case UserUpdated          = 'user.updated';
    case UserRolesChanged     = 'user.roles_changed';
    case UserActivated        = 'user.activated';
    case UserDeactivated      = 'user.deactivated';
    case UserDeleted          = 'user.deleted';
    case UserActivationResent = 'user.activation_resent';
    case RoleCreated          = 'role.created';
    case RoleUpdated          = 'role.updated';
    case RoleDeleted          = 'role.deleted';

    case SettingsUpdated            = 'settings.updated';
    case WorkspaceDeletionRequested = 'workspace.deletion_requested';
    case WorkspaceDeletionCancelled = 'workspace.deletion_cancelled';

    case SignedIn  = 'auth.signed_in';
    case SignedOut = 'auth.signed_out';

    public function area(): AuditArea
    {
        return match ($this) {
            self::AssistantCreated,
            self::AssistantUpdated,
            self::AssistantDeleted,
            self::AssistantSettingsUpdated => AuditArea::Assistants,

            self::ChannelCreated,
            self::ChannelUpdated,
            self::ChannelDeleted,
            self::ChannelTokenRotated,
            self::ChannelWebhookHashRotated => AuditArea::Channels,

            self::FlowCreated,
            self::FlowUpdated,
            self::FlowDeleted,
            self::FlowPublished,
            self::FlowGroupCreated,
            self::FlowGroupUpdated,
            self::FlowGroupDeleted => AuditArea::Flows,

            self::BroadcastCreated,
            self::BroadcastUpdated,
            self::BroadcastDeleted,
            self::BroadcastSent,
            self::BroadcastCancelled => AuditArea::Broadcasts,

            self::ContactUpdated,
            self::ContactDeleted,
            self::ContactGroupCreated,
            self::ContactGroupUpdated,
            self::ContactGroupDeleted,
            self::ContactSegmentCreated,
            self::ContactSegmentUpdated,
            self::ContactSegmentDeleted => AuditArea::Contacts,

            self::UserInvited,
            self::UserUpdated,
            self::UserRolesChanged,
            self::UserActivated,
            self::UserDeactivated,
            self::UserDeleted,
            self::UserActivationResent,
            self::RoleCreated,
            self::RoleUpdated,
            self::RoleDeleted => AuditArea::Staff,

            self::SettingsUpdated,
            self::WorkspaceDeletionRequested,
            self::WorkspaceDeletionCancelled => AuditArea::Settings,

            self::SignedIn,
            self::SignedOut => AuditArea::Auth,
        };
    }

    /**
     * The verb, stored as the entry's `event`: the part of the key after the dot.
     */
    public function event(): string
    {
        return mb_substr($this->value, (int) mb_strpos($this->value, '.') + 1);
    }
}
