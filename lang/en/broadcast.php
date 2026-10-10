<?php

declare(strict_types=1);

return [
    'label'        => 'Broadcast',
    'plural_label' => 'Broadcasts',

    'fields' => [
        'name'         => 'Name',
        'message'      => 'Message',
        'message_help' => 'Sent to each recipient in their language, falling back to the assistant\'s default language. Basic HTML is supported.',
        'target'       => 'Audience',
        'tags'         => 'Tags',
        'segment'      => 'Segment',
        'reach'        => 'Reach',
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

    'reach_count' => '{0} No reachable contacts|{1} :count contact will receive|[2,*] :count contacts will receive',

    'notifications' => [
        'started'         => 'Broadcast started.',
        'already_started' => 'Broadcast has already been started.',
        'cancelled'       => 'Broadcast cancelled.',
    ],

    'errors' => [
        'not_editable'                   => 'This broadcast has already been started and can no longer be edited.',
        'message_required_base_language' => 'Add message text for :language — it is this tenant\'s base language and can\'t be empty.',
    ],
];
