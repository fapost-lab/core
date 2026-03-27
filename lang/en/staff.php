<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Staff',
    ],

    'permission_groups' => [
        'bots'      => 'Bots',
        'users'     => 'Users',
        'content'   => 'Content',
        'contacts'  => 'Contacts',
        'analytics' => 'Analytics',
        'system'    => 'System',
    ],

    'permissions' => [
        'manage_bots'      => 'Manage bots',
        'manage_users'     => 'Manage users',
        'manage_flow'      => 'Manage flows',
        'manage_broadcast' => 'Manage broadcasts',
        'manage_rag'       => 'Manage RAG / knowledge',
        'view_contacts'    => 'View contacts',
        'manage_contacts'  => 'Manage contacts',
        'view_analytics'   => 'View analytics',
        'view_system'      => 'View system',
        'manage_settings'  => 'Manage settings',
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
