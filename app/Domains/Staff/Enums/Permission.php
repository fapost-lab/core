<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * Platform-level ACL permissions. Granular blocks per domain action.
 * Single source of truth for the permission surface — used for seeding, policy checks, and UI.
 *
 * Sensitive permissions are flagged by {@see isSensitive()} and shown with a
 * warning badge in the Roles UI to discourage accidental assignment.
 */
enum Permission: string
{
    // ── Assistants group ─────────────────────────────────────────────────────
    case ManageAssistants        = 'manage_assistants';
    case ManageChannels          = 'manage_channels';
    case RotateChannelToken      = 'rotate_channel_token';
    case ManageAssistantSettings = 'manage_assistant_settings';

    // ── Flow / Content group ──────────────────────────────────────────────────
    /**
     * @deprecated Use granular flow permissions: ManageFlowDefinitions,
     *             PublishFlow, ViewFlowSessions, ManageFlowGroups,
     *             ManageTranslations.
     */
    case ManageFlow            = 'manage_flow';
    case ManageFlowDefinitions = 'manage_flow_definitions';
    case PublishFlow           = 'publish_flow';
    case ViewFlowSessions      = 'view_flow_sessions';
    case ManageFlowGroups      = 'manage_flow_groups';
    case ManageTranslations    = 'manage_translations';
    case ManageBroadcast       = 'manage_broadcast';
    case ManageRag             = 'manage_rag';
    case ViewMedia             = 'view_media';
    case ManageMedia           = 'manage_media';

    // ── Users / Roles group ───────────────────────────────────────────────────
    case ManageUsers = 'manage_users';
    case ManageRoles = 'manage_roles';

    // ── Contacts group ────────────────────────────────────────────────────────
    case ViewContacts   = 'view_contacts';
    case ManageContacts = 'manage_contacts';

    // ── Conversations / Inbox group ───────────────────────────────────────────
    case ViewConversations  = 'view_conversations';
    case ReplyConversations = 'reply_conversations';

    // ── Analytics / System group ──────────────────────────────────────────────
    case ViewAnalytics  = 'view_analytics';
    case ViewSystem     = 'view_system';
    case ManageSettings = 'manage_settings';

    /**
     * Opens the operator screens (queue dashboard, and what the monitoring package adds) of a `single`
     * installation. Not {@see self::ViewSystem}, which gates the Usage and Support access screens.
     */
    case ViewOperatorScreens = 'view_operator_screens';

    /**
     * Opens the tenant's audit log: what each staff user (and platform support, inside the tenant) did in the console.
     */
    case ViewActivityLog = 'view_activity_log';

    /**
     * Opens the tenant's API tokens screen: issue, rotate and revoke the tokens outside systems authenticate with.
     * A token never holds it ({@see self::isForbiddenToTokens()}).
     */
    case ManageApiTokens = 'manage_api_tokens';

    // ── Automations group ─────────────────────────────────────────────────────
    /**
     * Makes live, changes or removes a trigger other than `message` (schedule, webhook, api, event) on publish, and
     * turns on a flow that has one.
     */
    case ManageAutomations = 'manage_automations';

    /**
     * Opens the tenant's credentials screen: list, create, replace and delete saved credentials.
     */
    case ManageCredentials = 'manage_credentials';

    /**
     * Picks a saved credential for a `call` node and tests a call with it, never seeing its value.
     */
    case UseCredentials = 'use_credentials';

    /**
     * Reads the full input and output of every node in a run's trace, outside services' answers included.
     */
    case ViewRunPayloads = 'view_run_payloads';

    /**
     * Starts a run of a published flow through its API trigger. Meant for a tenant API token; only an administrator may
     * put it on one ({@see self::isNarrowedByRecordPolicy()}).
     */
    case StartFlowRuns = 'start_flow_runs';

    /**
     * Runs a failed automation run again from the start, with the trigger payload it started with: every outgoing call
     * and message of the flow is sent again.
     */
    case RerunFlow = 'rerun_flow';

    /**
     * Enum cases grouped by their logical UI group.
     *
     * @return array<string, list<self>>
     */
    public static function groupedByGroup(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case;
        }

        return $groups;
    }

    /**
     * Flat grouped permission strings (group → [value, ...]).
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case->value;
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Human-readable label for this permission (translated via lang file).
     */
    public function label(): string
    {
        return __('staff.permissions.labels.' . $this->value);
    }

    /**
     * Optional longer description shown as tooltip in the Roles UI.
     * Empty string when no specific description is provided.
     */
    public function description(): string
    {
        $key        = 'staff.permissions.descriptions.' . $this->value;
        $translated = __($key);

        // Return empty string when no description is defined (key returned as-is).
        return $translated === $key ? '' : $translated;
    }

    /**
     * Whether this permission has elevated risk if assigned carelessly.
     * Sensitive permissions are shown with a warning badge in the Roles UI.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::RotateChannelToken,
            self::PublishFlow,
            self::ManageRoles,
            // Full conversation transcripts are the most sensitive data the
            // platform stores — everything a contact ever sent the assistant.
            self::ViewConversations,
            // Replying puts words in the assistant's mouth, to a real person.
            self::ReplyConversations,
            // Queue payloads and logs of the whole installation.
            self::ViewOperatorScreens,
            // Shows what every colleague did, and when.
            self::ViewActivityLog,
            // Starts runs nobody asked for, on a schedule or from outside systems.
            self::ManageAutomations,
            // Decides which outside systems the tenant's secrets open.
            self::ManageCredentials,
            // Spends a secret against an outside system.
            self::UseCredentials,
            // Whatever outside services answered, contact data included.
            self::ViewRunPayloads,
            // Mints credentials that outlive their issuer and reach the whole tenant.
            self::ManageApiTokens,
            // Lets an outside system start runs.
            self::StartFlowRuns,
            // Sends a run's calls to outside systems and its messages to people a second time.
            self::RerunFlow => true,
            default         => false,
        };
    }

    /**
     * Logical UI group for this permission (a section of the console's role form).
     */
    public function group(): string
    {
        return match ($this) {
            self::ManageAssistants,
            self::ManageChannels,
            self::RotateChannelToken,
            self::ManageAssistantSettings => 'assistants',

            self::ManageUsers,
            self::ManageRoles => 'users',

            self::ManageFlow,
            self::ManageFlowDefinitions,
            self::PublishFlow,
            self::ViewFlowSessions,
            self::ManageFlowGroups,
            self::ManageTranslations,
            self::ManageBroadcast,
            self::ManageRag,
            self::ViewMedia,
            self::ManageMedia => 'content',

            self::ViewContacts,
            self::ManageContacts => 'contacts',

            self::ViewConversations,
            self::ReplyConversations => 'conversations',

            self::ViewAnalytics => 'analytics',

            self::ViewSystem,
            self::ViewOperatorScreens,
            self::ViewActivityLog,
            self::ManageApiTokens,
            self::ManageSettings => 'system',

            self::ManageAutomations,
            self::ManageCredentials,
            self::UseCredentials,
            self::ViewRunPayloads,
            self::StartFlowRuns,
            self::RerunFlow => 'automations',
        };
    }

    /**
     * Whether a tenant API token may never hold this permission (`adr-20261119-tenant-api-tokens` §7): a token that
     * manages tokens renews itself through rotation, and staff, roles and the operator screens stay with people.
     * Issuing refuses these, and the Gate refuses them to a token even when its row holds one.
     */
    public function isForbiddenToTokens(): bool
    {
        return match ($this) {
            self::ManageApiTokens,
            self::ManageRoles,
            self::ManageUsers,
            self::ViewOperatorScreens => true,
            default                   => false,
        };
    }

    /**
     * Whether a record-level policy narrows this permission for a staff user who is not an administrator — by the
     * assistants they are assigned to, for instance. A token reaches the whole tenant, so a non-admin may not grant
     * such a permission to a token (`adr-20261119-tenant-api-tokens` §7).
     *
     * Cautious by default: only the permissions whose screens and policies are tenant-wide and never look at the
     * assignment are not narrowed. {@see self::StartFlowRuns} is narrowed: a token would start flows of any assistant.
     */
    public function isNarrowedByRecordPolicy(): bool
    {
        return match ($this) {
            self::ViewSystem,
            self::ManageSettings,
            self::ViewActivityLog,
            self::ManageCredentials => false,
            default                 => true,
        };
    }
}
