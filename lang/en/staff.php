<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Staff',
    ],

    'notifications' => [
        'escalation_subject' => 'Notification from :app',
    ],

    'tenant_settings' => [
        'title'      => 'Settings',
        'navigation' => 'Settings',
        'subtitle'   => 'Tenant-wide configuration shared across all assistants.',
        'saved'      => 'Settings saved',
        'tabs'       => [
            'languages'  => 'Languages',
            'runtime'    => 'Runtime',
            'broadcasts' => 'Broadcasts',
        ],
        'sections' => [
            'messaging' => 'Messaging',
            'flow'      => 'Flow execution',
        ],
        'fields' => [
            'content_base_language'      => 'Content base language',
            'content_base_language_help' => 'Source language used to author flow content. Cannot be changed after the first flow is published.',
            'available_languages'        => 'Available languages',
            'available_languages_help'   => 'Languages available for translation in the flow content manager.',
            'fallback_language'          => 'Fallback language',
            'fallback_language_help'     => 'Used when neither contact nor assistant define a language.',
            'messaging_rate_limit'       => 'Messages per minute (per chat)',
            'broadcast_chunk_size'       => 'Broadcast chunk size',
            'broadcast_backpressure'     => 'Broadcast backpressure',
            'flow_session_ttl'           => 'Flow session TTL (seconds)',
            'max_retry_attempts'         => 'Max node retry attempts',
            'flow_fallback_message'      => 'Flow fallback message',
        ],
        'actions' => [
            'save' => 'Save',
        ],
        'errors' => [
            'fallback_not_in_available' => 'Fallback language must be one of the available languages.',
            'base_lang_locked'          => 'Content base language cannot be changed after publishing flows.',
        ],
    ],

    'permission_groups' => [
        'assistants'    => 'Assistants',
        'users'         => 'Users',
        'content'       => 'Content',
        'contacts'      => 'Contacts',
        'conversations' => 'Conversations',
        'analytics'     => 'Analytics',
        'system'        => 'System',
    ],

    'permissions' => [
        'labels' => [
            'manage_assistants'         => 'Manage assistants',
            'manage_channels'           => 'Manage channels',
            'rotate_channel_token'      => 'Rotate channel webhook hash',
            'manage_assistant_settings' => 'Edit assistant settings',
            'manage_flow'               => 'Manage flows (legacy)',
            'manage_flow_definitions'   => 'Create / edit flow drafts',
            'publish_flow'              => 'Publish flow to live',
            'view_flow_sessions'        => 'View flow sessions & logs',
            'manage_flow_groups'        => 'Manage flow groups',
            'manage_translations'       => 'Edit translations',
            'manage_broadcast'          => 'Manage broadcasts',
            'manage_rag'                => 'Manage RAG / knowledge',
            'view_media'                => 'View media library',
            'manage_media'              => 'Manage media library',
            'manage_users'              => 'Manage users',
            'manage_roles'              => 'Manage roles',
            'view_contacts'             => 'View contacts',
            'manage_contacts'           => 'Manage contacts',
            'view_conversations'        => 'View conversations',
            'reply_conversations'       => 'Reply in conversations',
            'view_analytics'            => 'View analytics',
            'view_system'               => 'View system',
            'manage_settings'           => 'Manage settings',
        ],
        'descriptions' => [
            'rotate_channel_token' => 'Reissues the webhook URL hash. Existing webhook configurations become invalid until re-configured on the platform side.',
            'publish_flow'         => 'Promotes a draft to live. Takes effect immediately for all incoming sessions.',
            'manage_roles'         => 'Create and edit custom roles with their permission sets.',
            'manage_flow'          => 'Legacy coarse-grained permission. Covers all flow operations. Use granular permissions for new roles.',
            'view_conversations'   => 'Opens full message transcripts between contacts and the assistant, including media.',
            'reply_conversations'  => 'Sends messages to a contact from the operator inbox under the assistant identity.',
        ],
        'sensitive_warning' => 'Sensitive — review carefully before assigning.',
    ],

    'users' => [
        'label'        => 'User',
        'plural_label' => 'Users',

        'limit' => [
            'reached_title' => 'Staff limit reached',
            'hint'          => 'Limit reached (:current of :limit)',
        ],

        'fields' => [
            'name'     => 'Name',
            'email'    => 'Email',
            'phone'    => 'Phone',
            'role'     => 'Role',
            'roles'    => 'Roles',
            'password' => 'Password',
        ],

        'table' => [
            'name'   => 'Name',
            'email'  => 'Email',
            'phone'  => 'Phone',
            'status' => 'Status',
            'active' => 'Active',
            'roles'  => 'Roles',
        ],

        'status' => [
            'pending'   => 'Pending',
            'active'    => 'Active',
            'suspended' => 'Suspended',
        ],

        'actions' => [
            'resend_activation' => 'Resend activation',
            'deactivate'        => 'Deactivate',
            'activate'          => 'Activate',
        ],
    ],

    'assistants' => [
        'label'        => 'Assistant',
        'plural_label' => 'Assistants',

        'limit' => [
            'reached_title' => 'Assistant limit reached',
            'hint'          => 'Limit reached (:current of :limit)',
        ],

        'fields' => [
            'name'                => 'Name',
            'default_language'    => 'Default language',
            'available_languages' => 'Available languages',
            'is_active'           => 'Active',
            'fallback_message'    => 'Fallback message',
            'default_flow'        => 'Default flow',
            'default_flow_help'   => 'Available after Flow domain is enabled.',
            'settings'            => 'Settings',
            'settings_key'        => 'Key',
            'settings_value'      => 'Value',
            'settings_add'        => 'Add entry',
        ],

        'table' => [
            'updated_at' => 'Updated',
        ],

        'actions' => [
            'manage' => 'Manage',
        ],
    ],

    'channels' => [
        'label'        => 'Channel',
        'plural_label' => 'Channels',

        'limit' => [
            'reached_title' => 'Channel limit reached',
            'hint'          => 'Limit reached (:current of :limit)',
        ],

        'fields' => [
            'type'                 => 'Channel',
            'token'                => 'Token',
            'secret_token'         => 'Secret token',
            'secret_token_help'    => 'Used for Telegram X-Telegram-Bot-Api-Secret-Token verification.',
            'is_active'            => 'Active',
            'webhook_hash'         => 'Webhook hash',
            'webhook_hash_help'    => 'Public part of the webhook URL registered with the provider. Change it with the "Rotate webhook hash" action.',
            'bot_link'             => 'Bot',
            'bot_link_pending'     => 'Awaiting registration',
            'allowed_updates'      => 'Allowed updates',
            'allowed_updates_help' => 'Telegram update types that this webhook should receive.',
            'max_connections'      => 'Max connections',
            'max_connections_help' => 'Telegram webhook delivery parallelism, from 1 to 100. Defaults to 40.',
            'config'               => 'Channel settings',
            'config_key'           => 'Key',
            'config_value'         => 'Value',
            'config_add'           => 'Add setting',
            'updated_at'           => 'Updated',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
        ],

        'telegram_updates' => [
            'message'                   => 'Message',
            'edited_message'            => 'Edited message',
            'channel_post'              => 'Channel post',
            'edited_channel_post'       => 'Edited channel post',
            'business_connection'       => 'Business connection',
            'business_message'          => 'Business message',
            'edited_business_message'   => 'Edited business message',
            'deleted_business_messages' => 'Deleted business messages',
            'message_reaction'          => 'Message reaction',
            'message_reaction_count'    => 'Message reaction count',
            'inline_query'              => 'Inline query',
            'chosen_inline_result'      => 'Chosen inline result',
            'callback_query'            => 'Callback query',
            'shipping_query'            => 'Shipping query',
            'pre_checkout_query'        => 'Pre-checkout query',
            'purchased_paid_media'      => 'Purchased paid media',
            'poll'                      => 'Poll',
            'poll_answer'               => 'Poll answer',
            'my_chat_member'            => 'My chat member update',
            'chat_member'               => 'Chat member update',
            'chat_join_request'         => 'Chat join request',
            'chat_boost'                => 'Chat boost',
            'removed_chat_boost'        => 'Removed chat boost',
            'managed_bot'               => 'Managed bot',
        ],

        'actions' => [
            'rotate_webhook_hash'             => 'Rotate webhook hash',
            'rotate_webhook_hash_description' => 'The public webhook URL will change. Update the channel webhook configuration after rotation.',
            'generate_secret_token'           => 'Generate',
            'select_all'                      => 'Select all',
            'clear_all'                       => 'Clear',
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash rotated',
        ],
    ],

    'tenant_translations' => [
        'label'        => 'Translation',
        'plural_label' => 'Translations',
        'navigation'   => 'Translations',
        'subtitle'     => 'System messages and labels the bot sends to end users. Catalog defaults are pre-filled — fill any cell to override per language.',
        'saved'        => 'Translations saved',
        'reset_done'   => 'Overrides removed for this key',

        'fields' => [
            'key'                 => 'Key',
            'group'               => 'Group',
            'description'         => 'Description',
            'locale_default_hint' => 'System default: ":default"',
        ],

        'actions' => [
            'edit'  => 'Edit',
            'save'  => 'Save',
            'reset' => 'Reset to defaults',
        ],
    ],

    'roles' => [
        'label'        => 'Role',
        'plural_label' => 'Roles',

        'fields' => [
            'name'         => 'Name',
            'display_name' => 'Display name',
        ],

        'table' => [
            'name'         => 'Name',
            'display_name' => 'Display name',
            'system'       => 'System',
            'permissions'  => 'Permissions',
        ],
    ],

    'dashboard' => [
        'chart' => [
            'flow_activity' => [
                'heading'  => 'Flow Activity (last 14 days)',
                'executed' => 'Executed',
                'failed'   => 'Failed',
            ],
        ],
        'stats' => [
            'assistants' => [
                'label'       => 'Assistants',
                'description' => ':count active',
            ],
            'contacts' => [
                'label'       => 'Contacts',
                'description' => 'Total registered',
            ],
            'channels' => [
                'label'       => 'Channels',
                'description' => ':total total, :active connected',
            ],
            'published_flows' => [
                'label'       => 'Published flows',
                'description' => 'Deployed to production',
            ],
            'waiting_sessions' => [
                'label'       => 'Waiting sessions',
                'description' => 'Paused or awaiting user input',
            ],
            'staff_users' => [
                'label'       => 'Staff users',
                'description' => 'With access to this tenant',
            ],
        ],
    ],
];
