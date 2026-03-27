<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'permission_groups' => [
        'assistants' => 'Асистенти',
        'users'      => 'Користувачі',
        'content'    => 'Контент',
        'contacts'   => 'Контакти',
        'analytics'  => 'Аналітика',
        'system'     => 'Система',
    ],

    'permissions' => [
        'manage_assistants' => 'Керування асистентами',
        'manage_users'      => 'Керування користувачами',
        'manage_flow'       => 'Керування сценаріями (flow)',
        'manage_broadcast'  => 'Керування розсилками',
        'manage_rag'        => 'Керування RAG / базою знань',
        'view_contacts'     => 'Перегляд контактів',
        'manage_contacts'   => 'Керування контактами',
        'view_analytics'    => 'Перегляд аналітики',
        'view_system'       => 'Перегляд системи',
        'manage_settings'   => 'Керування налаштуваннями',
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

    'assistants' => [
        'label'        => 'Асистент',
        'plural_label' => 'Асистенти',

        'fields' => [
            'name'              => 'Назва',
            'is_active'         => 'Активний',
            'fallback_message'  => 'Повідомлення за замовчуванням',
            'default_flow'      => 'Сценарій за замовчуванням',
            'default_flow_help' => 'Буде доступно після ввімкнення домену Flow.',
            'settings'          => 'Налаштування',
            'settings_key'      => 'Ключ',
            'settings_value'    => 'Значення',
            'settings_add'      => 'Додати',
        ],

        'table' => [
            'updated_at' => 'Оновлено',
        ],

        'actions' => [
            'manage' => 'Керувати',
        ],
    ],

    'channels' => [
        'label'        => 'Канал',
        'plural_label' => 'Канали',

        'fields' => [
            'type'              => 'Канал',
            'token'             => 'Токен',
            'secret_token'      => 'Секретний токен',
            'secret_token_help' => 'Для перевірки заголовка Telegram X-Telegram-Bot-Api-Secret-Token.',
            'is_active'         => 'Активний',
            'webhook_hash'      => 'Webhook hash',
            'config'            => 'Налаштування каналу (ключ/значення)',
            'config_key'        => 'Ключ',
            'config_value'      => 'Значення',
            'config_add'        => 'Додати',
            'updated_at'        => 'Оновлено',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
        ],

        'actions' => [
            'rotate_webhook_hash'             => 'Ротувати webhook hash',
            'rotate_webhook_hash_description' => 'Публічний URL webhook зміниться. Оновіть налаштування webhook у каналі після ротації.',
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash оновлено',
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
