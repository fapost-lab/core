<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Дашборд',
        'menu'      => 'Меню',
    ],

    'breadcrumbs' => [
        'admin' => 'Адміністрування',
    ],

    'switcher' => [
        'label'         => 'Асистент',
        'back_to_admin' => 'Назад в адмінку',
    ],

    'theme' => [
        'label'  => 'Тема',
        'light'  => 'Світла',
        'dark'   => 'Темна',
        'system' => 'Системна',
    ],

    'language' => [
        'label' => 'Мова',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Обліковий запис',
        'sign_out' => 'Вийти',
    ],

    'support' => [
        'banner' => 'Ви увійшли як підтримка платформи (:name, :email).',
        'leave'  => 'Вийти',
    ],

    'dashboard' => [
        'title'    => 'Огляд асистента',
        'sections' => [
            'summary'    => 'Підсумок',
            'channels'   => 'Канали',
            'operations' => 'Операції',
        ],
        'name'             => 'Назва: :name',
        'status'           => 'Статус: :active',
        'status_active'    => 'Активний',
        'status_inactive'  => 'Неактивний',
        'channels_intro'   => 'У цього асистента :count канал(ів). Керуйте каналами в розділі «Канали».',
        'operations_intro' => 'Діагностика активних та нещодавніх запусків сценаріїв.',
        'live_sessions'    => 'Активні сесії (:count)',
        'errors_24h'       => 'Помилки за 24 години (:count)',
    ],
];
