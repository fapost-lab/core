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
            'group'     => 'Group',
        ],
    ],

    'operators' => [
        'has'     => 'has tag',
        'not_has' => 'does not have tag',
        'in'      => 'is any of',
        'not_in'  => 'is none of',
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

    'validation' => [
        'operator_mismatch' => 'This operator does not fit the chosen attribute.',
        'key_required'      => 'Enter the attribute key.',
        'key_invalid'       => 'Use letters, digits and underscores, with dots between the parts, for example profile.city.',
        'one_value'         => 'This condition compares with exactly one value. To match several, add a condition for each and set Match to "Any condition".',
        'value_required'    => 'Enter a value.',
        'language'          => 'Use a language code such as en or pt-BR.',
        'platform'          => 'Choose a supported platform.',
        'group_missing'     => 'This group no longer exists. Remove it from the condition.',
    ],
];
