<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'permission_groups' => [
        'bots'      => 'Боты',
        'users'     => 'Пользователи',
        'content'   => 'Контент',
        'contacts'  => 'Контакты',
        'analytics' => 'Аналитика',
        'system'    => 'Система',
    ],

    'permissions' => [
        'manage_bots'      => 'Управление ботами',
        'manage_users'     => 'Управление пользователями',
        'manage_flow'      => 'Управление сценариями (flow)',
        'manage_broadcast' => 'Управление рассылками',
        'manage_rag'       => 'Управление RAG / базой знаний',
        'view_contacts'    => 'Просмотр контактов',
        'manage_contacts'  => 'Управление контактами',
        'view_analytics'   => 'Просмотр аналитики',
        'view_system'      => 'Просмотр системы',
        'manage_settings'  => 'Управление настройками',
    ],

    'users' => [
        'label'        => 'Пользователь',
        'plural_label' => 'Пользователи',

        'fields' => [
            'name'     => 'Имя',
            'email'    => 'Email',
            'phone'    => 'Телефон',
            'role'     => 'Роль',
            'roles'    => 'Роли',
            'password' => 'Пароль',
        ],

        'table' => [
            'name'   => 'Имя',
            'email'  => 'Email',
            'phone'  => 'Телефон',
            'status' => 'Статус',
            'active' => 'Активен',
            'roles'  => 'Роли',
        ],

        'status' => [
            'pending'   => 'Ожидает активации',
            'active'    => 'Активен',
            'suspended' => 'Заблокирован',
        ],

        'actions' => [
            'resend_activation' => 'Отправить активацию повторно',
            'deactivate'        => 'Деактивировать',
            'activate'          => 'Активировать',
        ],
    ],

    'roles' => [
        'label'        => 'Роль',
        'plural_label' => 'Роли',

        'fields' => [
            'name'         => 'Системное имя',
            'display_name' => 'Отображаемое имя',
        ],

        'table' => [
            'name'         => 'Имя',
            'display_name' => 'Отображаемое имя',
            'system'       => 'Системная',
            'permissions'  => 'Права',
        ],
    ],
];
