<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'До адмін-панелі',
    ],
    'navigation' => [
        'groups' => [
            'overview' => 'Огляд',
            'channels' => 'Канали',
            'flow'     => 'Сценарії',
            'settings' => 'Налаштування',
        ],
    ],
    'pages' => [
        'overview' => [
            'title'    => 'Огляд асистента',
            'sections' => [
                'summary' => 'Підсумок',
            ],
            'fields' => [
                'name'   => 'Назва: :name',
                'status' => 'Статус: :active',
            ],
            'status_active'   => 'Активний',
            'status_inactive' => 'Неактивний',
            'channels_intro'  => 'У цього асистента :count канал(ів). Керуйте каналами в розділі «Канали».',
            'placeholders'    => [
                'flow'     => 'Конструктор сценаріїв з’явиться тут.',
                'settings' => 'Налаштування асистента з’являться тут.',
            ],
        ],
        'flow' => [
            'title'       => 'Сценарії',
            'placeholder' => 'Інструменти сценаріїв будуть доступні пізніше.',
        ],
        'settings' => [
            'title'       => 'Налаштування',
            'placeholder' => 'Налаштування асистента будуть доступні пізніше.',
        ],
    ],
];
