<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'permission_groups' => [
        'bots'      => 'Боти',
        'users'     => 'Користувачі',
        'content'   => 'Контент',
        'contacts'  => 'Контакти',
        'analytics' => 'Аналітика',
        'system'    => 'Система',
    ],

    'permissions' => [
        'manage_bots'      => 'Керування ботами',
        'manage_users'     => 'Керування користувачами',
        'manage_flow'      => 'Керування сценаріями (flow)',
        'manage_broadcast' => 'Керування розсилками',
        'manage_rag'       => 'Керування RAG / базою знань',
        'view_contacts'    => 'Перегляд контактів',
        'manage_contacts'  => 'Керування контактами',
        'view_analytics'   => 'Перегляд аналітики',
        'view_system'      => 'Перегляд системи',
        'manage_settings'  => 'Керування налаштуваннями',
    ],

    'users' => [
        'label'        => 'Користувач',
        'plural_label' => 'Користувачі',

        'fields' => [
            'name'     => 'Ім’я',
            'email'    => 'Email',
            'phone'    => 'Телефон',
            'role'     => 'Роль',
            'roles'    => 'Ролі',
            'password' => 'Пароль',
        ],

        'table' => [
            'name'   => 'Ім’я',
            'email'  => 'Email',
            'phone'  => 'Телефон',
            'status' => 'Статус',
            'active' => 'Активний',
            'roles'  => 'Ролі',
        ],

        'status' => [
            'pending'   => 'Очікує активації',
            'active'    => 'Активний',
            'suspended' => 'Заблокований',
        ],

        'actions' => [
            'resend_activation' => 'Надіслати активацію знову',
            'deactivate'        => 'Деактивувати',
            'activate'          => 'Активувати',
        ],
    ],

    'roles' => [
        'label'        => 'Роль',
        'plural_label' => 'Ролі',

        'fields' => [
            'name'         => 'Системна назва',
            'display_name' => 'Відображувана назва',
        ],

        'table' => [
            'name'         => 'Назва',
            'display_name' => 'Відображувана назва',
            'system'       => 'Системна',
            'permissions'  => 'Права',
        ],
    ],
];
