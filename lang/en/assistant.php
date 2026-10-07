<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'Back to Admin Panel',
    ],
    'navigation' => [
        'groups' => [
            'overview'   => 'Overview',
            'channels'   => 'Channels',
            'flow'       => 'Flow',
            'operations' => 'Operations',
            'settings'   => 'Settings',
        ],
    ],
    'flows' => [
        'label'        => 'Flow',
        'plural_label' => 'Flows',

        'limit' => [
            'reached_title' => 'Flow limit reached',
            'hint'          => 'Limit reached (:current of :limit)',
        ],

        'fields' => [
            'name'                 => 'Name',
            'description'          => 'Description',
            'group'                => 'Group',
            'group_name'           => 'Group name',
            'is_public'            => 'Public',
            'is_public_hint'       => 'If disabled, contact must have auth = true to start this flow',
            'is_active'            => 'Active',
            'logging_enabled'      => 'Log change history',
            'logging_enabled_hint' => 'Saves all user answers and data changes to the session history for later analysis.',
            'versions'             => 'Versions',
            'updated_at'           => 'Updated',
        ],
        'actions' => [
            'open_builder' => 'Builder',
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
            'operations' => [
                'intro'         => 'Diagnose live and recent flow executions.',
                'live_sessions' => 'Live sessions (:count)',
                'errors_24h'    => 'Errors 24 h (:count)',
            ],
        ],
        'settings' => [
            'title'   => 'Settings',
            'saved'   => 'Settings saved',
            'actions' => [
                'save' => 'Save',
            ],
            'tabs' => [
                'general'  => 'General',
                'commands' => 'Commands',
                'advanced' => 'Advanced',
            ],
            'fields' => [
                'default_language'           => 'Default language',
                'default_language_locked'    => 'Cannot be changed once flows exist',
                'available_languages'        => 'Available languages',
                'available_countries'        => 'Served countries',
                'available_countries_help'   => 'Countries this assistant serves — drives phone-number formats and validation in input nodes.',
                'default_flow_id'            => 'Default flow',
                'default_flow_create'        => 'Create new flow',
                'default_flow_create_modal'  => 'Create a new flow',
                'default_flow_create_submit' => 'Create & open builder',
                'fallback_message'           => 'Fallback message',
                'busy_message'               => 'Busy notice',
                'busy_message_help'          => 'Sent when the bot is processing a previous message. Defaults to a localised system text when empty.',
                'settings'                   => 'Settings',
                'commands'                   => 'Commands',
                'commands_help'              => 'Slash commands available across every flow. Built-ins (/reset, /cancel) may be redeclared only to override their reply text.',
            ],
            'commands' => [
                'add_label'  => 'Add command',
                'item_label' => 'Command',
                'fields'     => [
                    'command'       => 'Command',
                    'type'          => 'Action',
                    'response'      => 'Acknowledgement',
                    'response_help' => 'Optional message sent after the action completes.',
                    'flow_id'       => 'Flow to start',
                    'text'          => 'Message',
                ],
                'types' => [
                    'terminate_session' => 'Terminate session',
                    'start_flow'        => 'Start flow',
                    'send_message'      => 'Send message',
                ],
                'types_help' => [
                    'terminate_session' => 'Ends the current dialog without starting a new one. Use for emergency-exit commands like /cancel.',
                    'start_flow'        => 'Ends the current dialog and starts the selected flow.',
                    'send_message'      => 'Sends an informational reply without touching the current dialog.',
                ],
            ],
            'errors' => [
                'commands_invalid' => 'Commands are invalid: :error',
            ],
        ],
    ],
    'flow_sessions' => [
        'label'        => 'Session',
        'plural_label' => 'Sessions',
        'fields'       => [
            'id'              => 'Session',
            'contact'         => 'Contact',
            'flow'            => 'Flow',
            'status'          => 'Status',
            'end_status'      => 'End status',
            'current_node_id' => 'Current node',
            'parent'          => 'Parent session',
            'state'           => 'State',
            'version'         => 'Revision',
            'expires_at'      => 'Expires',
            'created_at'      => 'Started',
            'updated_at'      => 'Updated',
            'history'         => 'History',
        ],
        'history' => [
            'timestamp' => 'When',
            'node'      => 'Node',
            'event'     => 'Event',
            'path'      => 'Path',
            'payload'   => 'Payload',
        ],
        'statuses' => [
            'pending'            => 'Pending',
            'active'             => 'Active',
            'waiting_input'      => 'Waiting input',
            'paused'             => 'Paused',
            'paused_subflow'     => 'Paused (subflow)',
            'completed'          => 'Completed',
            'ended'              => 'Ended',
            'failed'             => 'Failed',
            'cancelled'          => 'Cancelled',
            'expired'            => 'Expired',
            'terminated_by_user' => 'Terminated by user',
        ],
        'filters' => [
            'live_only'    => 'Live only',
            'status'       => 'Status',
            'flow'         => 'Flow',
            'created_from' => 'From',
            'created_to'   => 'To',
        ],
    ],
    'flow_logs' => [
        'label'        => 'Log entry',
        'plural_label' => 'Flow logs',
        'fields'       => [
            'created_at'    => 'When',
            'session_id'    => 'Session',
            'node_id'       => 'Node',
            'node_type'     => 'Type',
            'node_version'  => 'V',
            'status'        => 'Status',
            'source_handle' => 'Handle',
            'state_changes' => 'State changes',
            'resolved'      => 'Resolved',
            'error'         => 'Error',
        ],
        'statuses' => [
            'executed' => 'Executed',
            'waiting'  => 'Waiting',
            'failed'   => 'Failed',
            'terminal' => 'Terminal',
        ],
        'filters' => [
            'session_id' => 'Session',
            'node_type'  => 'Node type',
            'status'     => 'Status',
            'has_error'  => 'Errors only',
            'from'       => 'From',
            'to'         => 'To',
        ],
    ],
];
