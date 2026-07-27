<?php

declare(strict_types=1);

return [
    'label'        => 'Broadcast',
    'plural_label' => 'Broadcasts',

    'fields' => [
        'name'         => 'Name',
        'message'      => 'Message',
        'message_help' => 'Sent as-is to every recipient. Basic HTML is supported.',
        'target'       => 'Audience',
        'tags'         => 'Tags',
        'segment'      => 'Segment',
        'status'       => 'Status',
        'progress'     => 'Sent',
        'failed'       => 'Failed',
        'created_at'   => 'Created',
    ],

    'targets' => [
        'all'     => 'All contacts',
        'tags'    => 'By tags',
        'segment' => 'By segment',
    ],

    'statuses' => [
        'draft'     => 'Draft',
        'running'   => 'Running',
        'completed' => 'Completed',
        'failed'    => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'actions' => [
        'send'               => 'Send',
        'send_confirm_title' => 'Send this broadcast?',
        'send_confirm_body'  => 'The message will be delivered to every reachable recipient in the selected audience. This cannot be undone.',
        'cancel'             => 'Cancel',
    ],

    'notifications' => [
        'started'         => 'Broadcast started.',
        'already_started' => 'Broadcast has already been started.',
        'cancelled'       => 'Broadcast cancelled.',
    ],
];
