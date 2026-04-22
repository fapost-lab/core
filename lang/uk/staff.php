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
            'default_language'  => 'Мова за замовчуванням',
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
            'type'                 => 'Канал',
            'token'                => 'Токен',
            'secret_token'         => 'Секретний токен',
            'secret_token_help'    => 'Для перевірки заголовка Telegram X-Telegram-Bot-Api-Secret-Token.',
            'is_active'            => 'Активний',
            'webhook_hash'         => 'Webhook hash',
            'allowed_updates'      => 'Allowed updates',
            'allowed_updates_help' => 'Типи Telegram-апдейтів, які має отримувати цей webhook.',
            'max_connections'      => 'Max connections',
            'max_connections_help' => 'Паралелізм доставки Telegram webhook, від 1 до 100. Типово 40.',
            'config'               => 'Налаштування каналу',
            'config_key'           => 'Ключ',
            'config_value'         => 'Значення',
            'config_add'           => 'Додати',
            'updated_at'           => 'Оновлено',
        ],

        'types' => [
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
        ],

        'telegram_updates' => [
            'message'                   => 'Повідомлення',
            'edited_message'            => 'Відредаговане повідомлення',
            'channel_post'              => 'Пост каналу',
            'edited_channel_post'       => 'Відредагований пост каналу',
            'business_connection'       => 'Бізнес-підключення',
            'business_message'          => 'Бізнес-повідомлення',
            'edited_business_message'   => 'Відредаговане бізнес-повідомлення',
            'deleted_business_messages' => 'Видалені бізнес-повідомлення',
            'message_reaction'          => 'Реакція на повідомлення',
            'message_reaction_count'    => 'Кількість реакцій',
            'inline_query'              => 'Inline-запит',
            'chosen_inline_result'      => 'Обраний inline-результат',
            'callback_query'            => 'Callback-запит',
            'shipping_query'            => 'Запит доставки',
            'pre_checkout_query'        => 'Запит перед оплатою',
            'purchased_paid_media'      => 'Покупка платного медіа',
            'poll'                      => 'Опитування',
            'poll_answer'               => 'Відповідь на опитування',
            'my_chat_member'            => 'Зміна статусу мого бота в чаті',
            'chat_member'               => 'Зміна учасника чату',
            'chat_join_request'         => 'Запит на вступ до чату',
            'chat_boost'                => 'Буст чату',
            'removed_chat_boost'        => 'Видалений буст чату',
            'managed_bot'               => 'Керований бот',
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
