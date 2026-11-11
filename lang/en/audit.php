<?php

declare(strict_types=1);

/*
| Names of the audit log's areas and actions (App\Domains\Audit\Enums). Every key of the enums is here in every
| language; an action missing here shows as its key on the screen. Past or impersonal forms, so they fit any gender.
*/
return [
    'areas' => [
        'assistants' => 'Assistants',
        'channels'   => 'Channels',
        'flows'      => 'Flows',
        'broadcasts' => 'Broadcasts',
        'contacts'   => 'Contacts',
        'staff'      => 'Team',
        'settings'   => 'Settings',
        'auth'       => 'Sign-in',
    ],

    // Nested by the key's dot: `assistant.created` is `assistant` => `created`, so `__('audit.actions.assistant.created')` finds it.
    'actions' => [
        'assistant' => [
            'created'          => 'Assistant created',
            'updated'          => 'Assistant updated',
            'deleted'          => 'Assistant deleted',
            'settings_updated' => 'Assistant settings updated',
        ],
        'channel' => [
            'created'              => 'Channel created',
            'updated'              => 'Channel updated',
            'deleted'              => 'Channel deleted',
            'token_rotated'        => 'Channel token rotated',
            'webhook_hash_rotated' => 'Channel webhook address rotated',
        ],
        'flow' => [
            'created'   => 'Flow created',
            'updated'   => 'Flow updated',
            'deleted'   => 'Flow deleted',
            'published' => 'Flow published',
        ],
        'flow_group' => [
            'created' => 'Flow group created',
            'updated' => 'Flow group updated',
            'deleted' => 'Flow group deleted',
        ],
        'broadcast' => [
            'created'   => 'Broadcast created',
            'updated'   => 'Broadcast updated',
            'deleted'   => 'Broadcast deleted',
            'sent'      => 'Broadcast sent',
            'cancelled' => 'Broadcast cancelled',
        ],
        'contact' => [
            'updated' => 'Contact updated',
            'deleted' => 'Contact deleted',
        ],
        'contact_group' => [
            'created' => 'Contact group created',
            'updated' => 'Contact group updated',
            'deleted' => 'Contact group deleted',
        ],
        'contact_segment' => [
            'created' => 'Segment created',
            'updated' => 'Segment updated',
            'deleted' => 'Segment deleted',
        ],
        'user' => [
            'invited'           => 'User invited',
            'updated'           => 'User updated',
            'roles_changed'     => 'User roles changed',
            'activated'         => 'User activated',
            'deactivated'       => 'User deactivated',
            'deleted'           => 'User deleted',
            'activation_resent' => 'Activation email resent',
        ],
        'role' => [
            'created' => 'Role created',
            'updated' => 'Role updated',
            'deleted' => 'Role deleted',
        ],
        'settings' => [
            'updated' => 'Workspace settings updated',
        ],
        'workspace' => [
            'deletion_requested' => 'Workspace deletion requested',
            'deletion_cancelled' => 'Workspace deletion cancelled',
        ],
        'auth' => [
            'signed_in'  => 'Signed in',
            'signed_out' => 'Signed out',
        ],
    ],
];
