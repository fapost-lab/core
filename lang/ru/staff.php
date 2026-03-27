<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'permission_groups' => [
        'assistants' => 'Ассистенты',
        'users'      => 'Пользователи',
        'content'    => 'Контент',
        'contacts'   => 'Контакты',
        'analytics'  => 'Аналитика',
        'system'     => 'Система',
    ],

    'permissions' => [
        'manage_assistants' => 'Управление ассистентами',
        'manage_users'      => 'Управление пользователями',
        'manage_flow'       => 'Управление сценариями (flow)',
        'manage_broadcast'  => 'Управление рассылками',
        'manage_rag'        => 'Управление RAG / базой знаний',
        'view_contacts'     => 'Просмотр контактов',
        'manage_contacts'   => 'Управление контактами',
        'view_analytics'    => 'Просмотр аналитики',
        'view_system'       => 'Просмотр системы',
        'manage_settings'   => 'Управление настройками',
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

    'assistants' => [
        'label'        => 'Ассистент',
        'plural_label' => 'Ассистенты',

        'fields' => [
            'name'              => 'Название',
            'is_active'         => 'Активен',
            'fallback_message'  => 'Сообщение по умолчанию',
            'default_flow'      => 'Сценарий по умолчанию',
            'default_flow_help' => 'Будет доступно после включения домена Flow.',
            'settings'          => 'Настройки',
            'settings_key'      => 'Ключ',
            'settings_value'    => 'Значение',
            'settings_add'      => 'Добавить',
        ],

        'table' => [
            'updated_at' => 'Обновлён',
        ],

        'actions' => [
            'manage' => 'Управление',
        ],
    ],

    'channels' => [
        'label'        => 'Канал',
        'plural_label' => 'Каналы',

        'fields' => [
            'type'              => 'Канал',
            'token'             => 'Токен',
            'secret_token'      => 'Секретный токен',
            'secret_token_help' => 'Для проверки заголовка Telegram X-Telegram-Bot-Api-Secret-Token.',
            'is_active'         => 'Активен',
            'webhook_hash'      => 'Webhook hash',
            'config'            => 'Настройки канала (ключ/значение)',
            'config_key'        => 'Ключ',
            'config_value'      => 'Значение',
            'config_add'        => 'Добавить',
            'updated_at'        => 'Обновлён',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
        ],

        'actions' => [
            'rotate_webhook_hash'             => 'Ротировать webhook hash',
            'rotate_webhook_hash_description' => 'Публичный URL webhook изменится. Обновите настройки webhook в канале после ротации.',
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash обновлён',
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
