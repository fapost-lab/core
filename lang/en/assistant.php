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
    'flows' => [
        'label'        => 'Flow',
        'plural_label' => 'Flows',
        'fields'       => [
            'name'           => 'Name',
            'description'    => 'Description',
            'group'          => 'Group',
            'group_name'     => 'Group name',
            'is_public'      => 'Public',
            'is_public_hint' => 'If disabled, contact must have auth = true to start this flow',
            'is_active'      => 'Active',
            'versions'       => 'Versions',
            'updated_at'     => 'Updated',
        ],
        'actions' => [
            'open_builder' => 'Open builder',
            'activate'     => 'Activate',
            'deactivate'   => 'Deactivate',
        ],
        'filters' => [
            'group'     => 'Group',
            'is_active' => 'Active',
        ],
        'visibility' => [
            'public'  => 'Public',
            'private' => 'Private',
        ],
        'groups' => [
            'ungrouped'            => 'No group',
            'create_modal_heading' => 'Create group',
        ],
        'delete_guard' => [
            'title' => 'Cannot delete',
            'body'  => 'This flow has active sessions. Deactivate it first.',
        ],
        'trigger_summary' => [
            'message'  => 'Message: keywords :keywords; phrases :phrases',
            'event'    => 'Event: :event',
            'schedule' => 'Schedule: :cron (:timezone)',
            'webhook'  => 'Webhook: :method :path',
            'api'      => 'API: :route_key; sources :allowed_sources',
            'unknown'  => 'Trigger configured',
        ],
    ],
    'flow_groups' => [
        'label'        => 'Group',
        'plural_label' => 'Groups',
        'fields'       => [
            'name'        => 'Name',
            'flows_count' => 'Flows',
        ],
        'delete_guard' => [
            'title' => 'Cannot delete',
            'body'  => 'This group has flows. Move or delete them first.',
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
        'settings' => [
            'title'   => 'Settings',
            'saved'   => 'Settings saved',
            'actions' => [
                'save' => 'Save',
            ],
            'sections' => [
                'general'  => 'General',
                'advanced' => 'Advanced',
            ],
            'fields' => [
                'default_language'        => 'Default language',
                'default_language_locked' => 'Cannot be changed once flows exist',
                'available_languages'     => 'Available languages',
                'default_flow_id'         => 'Default flow',
                'fallback_message'        => 'Fallback message',
                'settings'                => 'Settings',
            ],
        ],
    ],
];
