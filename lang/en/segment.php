<?php

declare(strict_types=1);

return [
    'label'        => 'Segment',
    'plural_label' => 'Segments',

    'fields' => [
        'name'       => 'Name',
        'match'      => 'Match',
        'conditions' => 'Conditions',
        'size'       => 'Size',
        'counted_at' => 'Counted',
    ],

    'match' => [
        'all' => 'All conditions (AND)',
        'any' => 'Any condition (OR)',
    ],

    'condition' => [
        'type'       => 'Attribute',
        'key'        => 'Key',
        'operator'   => 'Operator',
        'value'      => 'Value',
        'value_help' => 'One tag, or one or more languages / platforms / values.',
        'types'      => [
            'tag'       => 'Tag',
            'language'  => 'Language',
            'platform'  => 'Platform',
            'attribute' => 'Attribute',
        ],
    ],

    'operators' => [
        'has'     => 'has tag',
        'not_has' => 'does not have tag',
        'in'      => 'is any of',
        'eq'      => 'equals',
        'ne'      => 'not equals',
        'exists'  => 'is set',
    ],

    'actions' => [
        'add_condition' => 'Add condition',
        'refresh_count' => 'Recount',
    ],

    'notifications' => [
        'counted' => ':count contacts match this segment.',
    ],
];
