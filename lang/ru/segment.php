<?php

declare(strict_types=1);

return [
    'label'        => 'Сегмент',
    'plural_label' => 'Сегменты',

    'fields' => [
        'name'       => 'Название',
        'match'      => 'Совпадение',
        'conditions' => 'Условия',
        'size'       => 'Размер',
        'counted_at' => 'Посчитано',
    ],

    'match' => [
        'all' => 'Все условия (И)',
        'any' => 'Любое условие (ИЛИ)',
    ],

    'condition' => [
        'type'       => 'Атрибут',
        'operator'   => 'Оператор',
        'value'      => 'Значение',
        'value_help' => 'Один тег, либо один или несколько языков / платформ.',
        'types'      => [
            'tag'      => 'Тег',
            'language' => 'Язык',
            'platform' => 'Платформа',
        ],
    ],

    'operators' => [
        'has'     => 'имеет тег',
        'not_has' => 'не имеет тег',
        'in'      => 'один из',
        'eq'      => 'равно',
    ],

    'actions' => [
        'add_condition' => 'Добавить условие',
        'refresh_count' => 'Пересчитать',
    ],

    'notifications' => [
        'counted' => 'Сегменту соответствует контактов: :count.',
    ],
];
