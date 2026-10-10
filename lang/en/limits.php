<?php

declare(strict_types=1);

return [
    'title' => 'Limit reached: :limit',

    'keys' => [
        'assistants'              => 'Assistants',
        'flows'                   => 'Flows',
        'channels'                => 'Channels',
        'staff'                   => 'Staff accounts',
        'monthly_active_contacts' => 'Monthly active contacts',
        'outbound_messages'       => 'Outbound messages',
        'call_executions'         => 'Call executions',
        'media_storage'           => 'Media storage',
    ],

    'usage'         => 'Used :used of :limit.',
    'usage_period'  => 'Used :used of :limit in the current period.',
    'usage_unknown' => 'The limit is :limit.',

    'filled' => 'The last available place under this limit has just been taken.',

    'refused' => [
        'record_creation'  => 'A new record could not be created.',
        'inbound_message'  => 'A message from a new contact was not processed, so the bot did not answer it.',
        'outbound_message' => 'An outgoing message was not sent.',
        'call_execution'   => 'A call from a flow was not made.',
        'media_upload'     => 'A file was not uploaded.',
        'inbound_media'    => 'A file received from a client was not stored.',
        'other'            => 'Some work was turned away.',
    ],

    'consequence' => [
        'records'    => 'Nothing new can be created until the limit is raised or something is removed.',
        'per_period' => 'This work is turned away until the limit is raised or a new period starts.',
        'bytes'      => 'New files are not stored until the limit is raised or files are deleted.',
    ],

    'who_to_ask' => 'To raise the limit, contact the platform administrator.',

    'open_panel' => 'Open the panel',

    'mail' => [
        'greeting' => 'Hello, :name.',
    ],
];
