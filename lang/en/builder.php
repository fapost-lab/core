<?php

declare(strict_types=1);

return [
    'topbar' => [
        'back'                   => 'Back',
        'language'               => 'Interface language',
        'tab_builder'            => 'Builder',
        'tab_content'            => 'Content',
        'rollback'               => 'Rollback',
        'preview'                => 'Preview',
        'validate'               => 'Validate',
        'save_draft'             => 'Save draft',
        'publish'                => 'Publish',
        'status_saving'          => 'Saving…',
        'status_saved'           => '✓ Saved',
        'status_conflict'        => '⚠ Conflict',
        'status_error'           => '✕ Error',
        'dirty_title'            => 'Unsaved changes',
        'reload'                 => 'Reload',
        'reload_title'           => 'Reload the draft from the server',
        'reload_confirm_title'   => 'Reload from the server?',
        'reload_confirm_message' => 'This draft was changed elsewhere, so nothing you edited since the conflict has been saved. Reloading brings back the server version and discards those edits.',
        'reload_confirm_ok'      => 'Reload',
        'reload_confirm_cancel'  => 'Stay',
    ],

    'nodes' => [
        'set_tag' => [
            'section'   => 'Tagging',
            'action'    => 'Action',
            'tags'      => 'Tags',
            'tags_help' => 'Language-agnostic labels. Not translated. Supports {{flow.*}} templates.',
            'actions'   => [
                'add'    => 'Add',
                'remove' => 'Remove',
                'toggle' => 'Toggle',
            ],
        ],

        'notify' => [
            'section_mode' => 'Mode',
            'mode'         => 'Notify',
            'modes'        => [
                'staff'    => 'Staff (admin users)',
                'contacts' => 'Contacts (via assistant)',
            ],
            'section_staff'    => 'Staff recipients',
            'target'           => 'Recipients',
            'role'             => 'Role',
            'user_ids'         => 'Users',
            'channel'          => 'Channel',
            'section_contacts' => 'Contact recipients',
            'assistant'        => 'Assistant',
            'contact_target'   => 'Audience',
            'contact_targets'  => [
                'tag' => 'By tag',
                'all' => 'All assistant contacts',
            ],
            'tags'            => 'Tags',
            'section_message' => 'Message',
            'message'         => 'Notification text',
            'message_help'    => 'Staff: admin-UI language. Contacts: content language. Supports {{flow.*}} / {{system.*}} templates.',
            'targets'         => [
                'assistant' => 'All assistant staff',
                'role'      => 'By role',
                'users'     => 'Specific users',
            ],
            'channels' => [
                'in_app' => 'In-app (admin panel)',
                'email'  => 'Email',
                'all'    => 'All available channels',
            ],
        ],

        'auth_request' => [
            'section'         => 'Authentication',
            'method'          => 'Method',
            'variable'        => 'Variable to check',
            'operator'        => 'Operator',
            'value'           => 'Expected value',
            'condition_label' => 'Authenticate when',
            'flag_hint'       => 'When the check passes, the contact is marked authenticated; the flow then continues from the single output. Verify the flag later with a Condition node, or rely on the private-flow access gate.',
            'methods'         => [
                'basic' => 'Basic (variable check)',
            ],
        ],
    ],

    'operators' => [
        'eq'        => '= equals',
        'neq'       => '≠ not equals',
        'gt'        => '> greater than',
        'gte'       => '≥ greater or equal',
        'lt'        => '< less than',
        'lte'       => '≤ less or equal',
        'contains'  => 'contains',
        'in'        => 'in list',
        'empty'     => 'is empty',
        'not_empty' => 'is not empty',
    ],
];
