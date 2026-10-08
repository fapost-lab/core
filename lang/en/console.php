<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Dashboard',
        'menu'      => 'Menu',
    ],

    'breadcrumbs' => [
        'admin' => 'Administration',
    ],

    'switcher' => [
        'label'         => 'Assistant',
        'back_to_admin' => 'Back to Admin Panel',
    ],

    'theme' => [
        'label'  => 'Theme',
        'light'  => 'Light',
        'dark'   => 'Dark',
        'system' => 'System',
    ],

    'language' => [
        'label' => 'Language',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Account',
        'sign_out' => 'Sign out',
    ],

    'support' => [
        'banner' => 'You are signed in as platform support (:name, :email).',
        'leave'  => 'Sign out',
    ],

    'dashboard' => [
        'title'    => 'Assistant overview',
        'sections' => [
            'summary'    => 'Summary',
            'channels'   => 'Channels',
            'operations' => 'Operations',
        ],
        'name'             => 'Name: :name',
        'status'           => 'Status: :active',
        'status_active'    => 'Active',
        'status_inactive'  => 'Inactive',
        'channels_intro'   => 'This assistant has :count channel(s). Manage channels in the Channels section.',
        'operations_intro' => 'Diagnose live and recent flow executions.',
        'live_sessions'    => 'Live sessions (:count)',
        'errors_24h'       => 'Errors 24 h (:count)',
    ],
];
