<?php

declare(strict_types=1);

return [
    'label'        => 'Conversation',
    'plural_label' => 'Conversations',

    'fields' => [
        'contact'       => 'Contact',
        'platform'      => 'Channel',
        'last_message'  => 'Last message',
        'unread'        => 'Unread',
        'messages'      => 'Messages',
        'status'        => 'Status',
        'last_activity' => 'Last activity',
    ],

    'statuses' => [
        'open'    => 'Open',
        'closed'  => 'Closed',
        'snoozed' => 'Snoozed',
    ],

    'content_types' => [
        'text'     => 'Text',
        'photo'    => 'Photo',
        'document' => 'Document',
        'video'    => 'Video',
        'voice'    => 'Voice message',
        'audio'    => 'Audio',
        'location' => 'Location',
        'contact'  => 'Contact card',
        'callback' => 'Button press',
        'unknown'  => 'Attachment',
    ],

    'delivery' => [
        'received'  => 'Received',
        'queued'    => 'Queued',
        'sent'      => 'Sent',
        'delivered' => 'Delivered',
        'read'      => 'Read',
        'failed'    => 'Failed',
    ],

    'message_count' => '{0} No messages|{1} :count message|[2,*] :count messages',
    'today'         => 'Today',
    'attachment'    => 'Attachment',
    'empty'         => 'No messages in this conversation yet.',
];
