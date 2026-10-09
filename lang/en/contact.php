<?php

declare(strict_types=1);

return [
    'label'        => 'Contact',
    'plural_label' => 'Contacts',
    'navigation'   => [
        'group' => 'Contacts',
    ],
    'fields' => [
        'id'          => 'ID',
        'platform'    => 'Channel',
        'external_id' => 'Channel user ID',
        'language'    => 'Language',
        'name'        => 'Name',
        'username'    => 'Username',
        'created_at'  => 'Created',
        'updated_at'  => 'Updated',
    ],
    'tags' => [
        'label'  => 'Tags',
        'manage' => 'Manage tags',
    ],
    'groups' => [
        'label'  => 'Groups',
        'manage' => 'Manage groups',
    ],
    'sections' => [
        'header'        => 'Identity',
        'profile'       => 'Profile',
        'from_platform' => 'From platform',
        'group_fields'  => ':count field|:count fields',
    ],
    'values' => [
        'yes' => 'Yes',
        'no'  => 'No',
    ],
    'placeholders' => [
        'empty' => '—',
    ],
    'filters' => [
        'platform' => 'Channel',
        'language' => 'Language',
    ],
];
