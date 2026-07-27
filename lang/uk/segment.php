<?php

declare(strict_types=1);

return [
    'label'        => 'Сегмент',
    'plural_label' => 'Сегменти',

    'fields' => [
        'name'       => 'Назва',
        'match'      => 'Збіг',
        'conditions' => 'Умови',
        'size'       => 'Розмір',
        'counted_at' => 'Пораховано',
    ],

    'match' => [
        'all' => 'Усі умови (І)',
        'any' => 'Будь-яка умова (АБО)',
    ],

    'condition' => [
        'type'       => 'Атрибут',
        'key'        => 'Ключ',
        'operator'   => 'Оператор',
        'value'      => 'Значення',
        'value_help' => 'Один тег, або одна чи кілька мов / платформ / значень.',
        'types'      => [
            'tag'       => 'Тег',
            'language'  => 'Мова',
            'platform'  => 'Платформа',
            'attribute' => 'Атрибут',
        ],
    ],

    'operators' => [
        'has'     => 'має тег',
        'not_has' => 'не має тег',
        'in'      => 'один із',
        'eq'      => 'дорівнює',
        'ne'      => 'не дорівнює',
        'exists'  => 'задано',
    ],

    'actions' => [
        'add_condition' => 'Додати умову',
        'refresh_count' => 'Перерахувати',
    ],

    'notifications' => [
        'counted' => 'Сегменту відповідає контактів: :count.',
    ],
];
