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
        'view_media'        => 'Просмотр медиа-библиотеки',
        'manage_media'      => 'Управление медиа-библиотекой',
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
            'name'                => 'Название',
            'default_language'    => 'Язык по умолчанию',
            'available_languages' => 'Доступные языки',
            'is_active'           => 'Активен',
            'fallback_message'    => 'Сообщение по умолчанию',
            'default_flow'        => 'Сценарий по умолчанию',
            'default_flow_help'   => 'Будет доступно после включения домена Flow.',
            'settings'            => 'Настройки',
            'settings_key'        => 'Ключ',
            'settings_value'      => 'Значение',
            'settings_add'        => 'Добавить',
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
            'type'                 => 'Канал',
            'token'                => 'Токен',
            'secret_token'         => 'Секретный токен',
            'secret_token_help'    => 'Для проверки заголовка Telegram X-Telegram-Bot-Api-Secret-Token.',
            'is_active'            => 'Активен',
            'webhook_hash'         => 'Webhook hash',
            'allowed_updates'      => 'Allowed updates',
            'allowed_updates_help' => 'Типы Telegram-апдейтов, которые должен получать этот webhook.',
            'max_connections'      => 'Max connections',
            'max_connections_help' => 'Параллелизм доставки Telegram webhook, от 1 до 100. По умолчанию 40.',
            'config'               => 'Настройки канала',
            'config_key'           => 'Ключ',
            'config_value'         => 'Значение',
            'config_add'           => 'Добавить',
            'updated_at'           => 'Обновлён',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
        ],

        'telegram_updates' => [
            'message'                   => 'Сообщение',
            'edited_message'            => 'Отредактированное сообщение',
            'channel_post'              => 'Пост канала',
            'edited_channel_post'       => 'Отредактированный пост канала',
            'business_connection'       => 'Бизнес-подключение',
            'business_message'          => 'Бизнес-сообщение',
            'edited_business_message'   => 'Отредактированное бизнес-сообщение',
            'deleted_business_messages' => 'Удалённые бизнес-сообщения',
            'message_reaction'          => 'Реакция на сообщение',
            'message_reaction_count'    => 'Количество реакций',
            'inline_query'              => 'Inline-запрос',
            'chosen_inline_result'      => 'Выбранный inline-результат',
            'callback_query'            => 'Callback-запрос',
            'shipping_query'            => 'Запрос доставки',
            'pre_checkout_query'        => 'Запрос перед оплатой',
            'purchased_paid_media'      => 'Покупка платного медиа',
            'poll'                      => 'Опрос',
            'poll_answer'               => 'Ответ на опрос',
            'my_chat_member'            => 'Изменение статуса моего бота в чате',
            'chat_member'               => 'Изменение участника чата',
            'chat_join_request'         => 'Запрос на вступление в чат',
            'chat_boost'                => 'Буст чата',
            'removed_chat_boost'        => 'Удалённый буст чата',
            'managed_bot'               => 'Управляемый бот',
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

    'dashboard' => [
        'chart' => [
            'flow_activity' => [
                'heading'  => 'Активность сценариев (последние 14 дней)',
                'executed' => 'Выполнено',
                'failed'   => 'Ошибки',
            ],
        ],
        'stats' => [
            'assistants' => [
                'label'       => 'Ассистенты',
                'description' => ':count активны',
            ],
            'contacts' => [
                'label'       => 'Контакты',
                'description' => 'Всего зарегистрировано',
            ],
            'channels' => [
                'label'       => 'Каналы',
                'description' => 'Всего :total, подключено :active',
            ],
            'published_flows' => [
                'label'       => 'Опубликованные сценарии',
                'description' => 'Развёрнуто в продакшн',
            ],
            'waiting_sessions' => [
                'label'       => 'Ожидающие сессии',
                'description' => 'На паузе или ждут ввода',
            ],
            'staff_users' => [
                'label'       => 'Сотрудники',
                'description' => 'С доступом к этому тенанту',
            ],
        ],
    ],
];
