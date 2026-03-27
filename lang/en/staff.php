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
            'type'              => 'Channel',
            'token'             => 'Token',
            'secret_token'      => 'Secret token',
            'secret_token_help' => 'Used for Telegram X-Telegram-Bot-Api-Secret-Token verification.',
            'is_active'         => 'Active',
            'webhook_hash'      => 'Webhook hash',
            'config'            => 'Channel settings (JSON key/value)',
            'config_key'        => 'Key',
            'config_value'      => 'Value',
            'config_add'        => 'Add setting',
            'updated_at'        => 'Updated',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
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
