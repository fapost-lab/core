<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'Back to Admin Panel',
    ],
    'navigation' => [
        'groups' => [
            'overview' => 'Overview',
            'channels' => 'Channels',
            'flow'     => 'Flow',
            'settings' => 'Settings',
        ],
    ],
    'pages' => [
        'overview' => [
            'title'    => 'Assistant overview',
            'sections' => [
                'summary' => 'Summary',
            ],
            'fields' => [
                'name'   => 'Name: :name',
                'status' => 'Status: :active',
            ],
            'status_active'   => 'Active',
            'status_inactive' => 'Inactive',
            'channels_intro'  => 'This assistant has :count channel(s). Manage channels in the Channels section.',
            'placeholders'    => [
                'flow'     => 'Flow builder and automation will appear here.',
                'settings' => 'Assistant-specific settings will appear here.',
            ],
        ],
        'flow' => [
            'title'       => 'Flow',
            'placeholder' => 'Flow tools will be available in a future release.',
        ],
        'settings' => [
            'title'       => 'Settings',
            'placeholder' => 'Assistant settings will be available in a future release.',
        ],
    ],
];
