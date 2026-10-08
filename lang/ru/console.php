<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Дашборд',
        'menu'      => 'Меню',
    ],

    'breadcrumbs' => [
        'admin' => 'Администрирование',
    ],

    'switcher' => [
        'label'         => 'Ассистент',
        'back_to_admin' => 'Назад в админку',
    ],

    'theme' => [
        'label'  => 'Тема',
        'light'  => 'Светлая',
        'dark'   => 'Тёмная',
        'system' => 'Системная',
    ],

    'language' => [
        'label' => 'Язык',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Аккаунт',
        'sign_out' => 'Выйти',
    ],

    'support' => [
        'banner' => 'Вы вошли как поддержка платформы (:name, :email).',
        'leave'  => 'Выйти',
    ],

    'dashboard' => [
        'title'    => 'Обзор ассистента',
        'sections' => [
            'summary'    => 'Сводка',
            'channels'   => 'Каналы',
            'operations' => 'Операции',
        ],
        'name'             => 'Имя: :name',
        'status'           => 'Статус: :active',
        'status_active'    => 'Активен',
        'status_inactive'  => 'Неактивен',
        'channels_intro'   => 'У этого ассистента :count канал(ов). Управляйте каналами в разделе «Каналы».',
        'operations_intro' => 'Диагностика активных и недавних запусков сценариев.',
        'live_sessions'    => 'Активные сессии (:count)',
        'errors_24h'       => 'Ошибки за 24 часа (:count)',
    ],
];
