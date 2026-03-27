<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'В админ-панель',
    ],
    'navigation' => [
        'groups' => [
            'overview' => 'Обзор',
            'channels' => 'Каналы',
            'flow'     => 'Сценарии',
            'settings' => 'Настройки',
        ],
    ],
    'pages' => [
        'overview' => [
            'title'    => 'Обзор ассистента',
            'sections' => [
                'summary' => 'Сводка',
            ],
            'fields' => [
                'name'   => 'Имя: :name',
                'status' => 'Статус: :active',
            ],
            'status_active'   => 'Активен',
            'status_inactive' => 'Неактивен',
            'channels_intro'  => 'У этого ассистента :count канал(ов). Управляйте каналами в разделе «Каналы».',
            'placeholders'    => [
                'flow'     => 'Конструктор сценариев появится здесь.',
                'settings' => 'Настройки ассистента появятся здесь.',
            ],
        ],
        'flow' => [
            'title'       => 'Сценарии',
            'placeholder' => 'Инструменты сценариев будут доступны позже.',
        ],
        'settings' => [
            'title'       => 'Настройки',
            'placeholder' => 'Настройки ассистента будут доступны позже.',
        ],
    ],
];
