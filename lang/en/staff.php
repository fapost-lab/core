<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Staff',
    ],

    'permission_groups' => [
        'assistants' => 'Assistants',
        'users'      => 'Users',
        'content'    => 'Content',
        'contacts'   => 'Contacts',
        'analytics'  => 'Analytics',
        'system'     => 'System',
    ],

    'permissions' => [
        'manage_assistants' => 'Manage assistants',
        'manage_users'      => 'Manage users',
        'manage_flow'       => 'Manage flows',
        'manage_broadcast'  => 'Manage broadcasts',
        'manage_rag'        => 'Manage RAG / knowledge',
        'view_contacts'     => 'View contacts',
        'manage_contacts'   => 'Manage contacts',
        'view_analytics'    => 'View analytics',
        'view_system'       => 'View system',
        'manage_settings'   => 'Manage settings',
    ],

    'users' => [
        'label'        => 'User',
        'plural_label' => 'Users',

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

        'fields' => [
            'name'              => 'Name',
            'default_language'  => 'Default language',
            'is_active'         => 'Active',
            'fallback_message'  => 'Fallback message',
            'default_flow'      => 'Default flow',
            'default_flow_help' => 'Available after Flow domain is enabled.',
            'settings'          => 'Settings',
            'settings_key'      => 'Key',
            'settings_value'    => 'Value',
            'settings_add'      => 'Add entry',
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

        'fields' => [
            'type'                 => 'Channel',
            'token'                => 'Token',
            'secret_token'         => 'Secret token',
            'secret_token_help'    => 'Used for Telegram X-Telegram-Bot-Api-Secret-Token verification.',
            'is_active'            => 'Active',
            'webhook_hash'         => 'Webhook hash',
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
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash rotated',
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
];
