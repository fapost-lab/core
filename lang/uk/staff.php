<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'notifications' => [
        'escalation_subject' => 'Сповіщення від :app',
    ],

    'tenant_settings' => [
        'title'      => 'Налаштування',
        'navigation' => 'Налаштування',
        'subtitle'   => 'Загальні налаштування, що поширюються на всіх асистентів тенанта.',
        'saved'      => 'Налаштування збережено',
        'tabs'       => [
            'languages'  => 'Мови',
            'runtime'    => 'Виконання',
            'broadcasts' => 'Розсилки',
        ],
        'sections' => [
            'messaging' => 'Повідомлення',
            'flow'      => 'Сценарії',
        ],
        'fields' => [
            'content_base_language'      => 'Базова мова контенту',
            'content_base_language_help' => 'Мова, якою створюються сценарії. Не можна змінити після публікації першого сценарію.',
            'available_languages'        => 'Доступні мови',
            'available_languages_help'   => 'Мови, на які можна перекласти контент у редакторі сценаріїв.',
            'fallback_language'          => 'Резервна мова',
            'fallback_language_help'     => 'Використовується, коли в контакта та асистента не визначено мову.',
            'messaging_rate_limit'       => 'Повідомлень на хвилину (на чат)',
            'broadcast_chunk_size'       => 'Розмір частини розсилки',
            'broadcast_backpressure'     => 'Backpressure для розсилок',
            'flow_session_ttl'           => 'TTL сесії сценарію (с)',
            'max_retry_attempts'         => 'Макс. кількість ретраїв вузла',
            'flow_fallback_message'      => 'Резервне повідомлення сценарію',
        ],
        'actions' => [
            'save' => 'Зберегти',
        ],
        'errors' => [
            'fallback_not_in_available' => 'Резервна мова має бути в списку доступних.',
            'base_lang_locked'          => 'Не можна змінити базову мову після публікації сценаріїв.',
        ],
    ],

    'permission_groups' => [
        'assistants'    => 'Асистенти',
        'users'         => 'Користувачі',
        'content'       => 'Контент',
        'contacts'      => 'Контакти',
        'conversations' => 'Діалоги',
        'analytics'     => 'Аналітика',
        'system'        => 'Система',
    ],

    'permissions' => [
        'labels' => [
            'manage_assistants'         => 'Керування асистентами',
            'manage_channels'           => 'Керування каналами',
            'rotate_channel_token'      => 'Ротація webhook-хешу каналу',
            'manage_assistant_settings' => 'Налаштування асистента',
            'manage_flow'               => 'Керування сценаріями (застаріле)',
            'manage_flow_definitions'   => 'Створення / редагування чернеток сценаріїв',
            'publish_flow'              => 'Публікація сценарію',
            'view_flow_sessions'        => 'Перегляд сесій та логів сценаріїв',
            'manage_flow_groups'        => 'Керування групами сценаріїв',
            'manage_translations'       => 'Редагування перекладів',
            'manage_broadcast'          => 'Керування розсилками',
            'manage_rag'                => 'Керування RAG / базою знань',
            'view_media'                => 'Перегляд медіа-бібліотеки',
            'manage_media'              => 'Керування медіа-бібліотекою',
            'manage_users'              => 'Керування користувачами',
            'manage_roles'              => 'Керування ролями',
            'view_contacts'             => 'Перегляд контактів',
            'manage_contacts'           => 'Керування контактами',
            'view_conversations'        => 'Перегляд діалогів',
            'reply_conversations'       => 'Відповіді в діалогах',
            'view_analytics'            => 'Перегляд аналітики',
            'view_system'               => 'Перегляд системи',
            'manage_settings'           => 'Керування налаштуваннями',
        ],
        'descriptions' => [
            'rotate_channel_token' => 'Перевипускає хеш webhook URL. Поточні конфігурації webhook стають недійсними до переналаштування.',
            'publish_flow'         => 'Переводить чернетку у прод. Набирає чинності негайно для всіх вхідних сесій.',
            'manage_roles'         => 'Створення та редагування користувацьких ролей та їх наборів прав.',
            'manage_flow'          => 'Застаріле право. Охоплює всі операції зі сценаріями. Використовуйте гранулярні права для нових ролей.',
            'view_conversations'   => 'Відкриває повні транскрипти листування контактів з асистентом, включно з медіа.',
            'reply_conversations'  => 'Надсилає повідомлення контакту з операторського інбоксу від імені асистента.',
        ],
        'sensitive_warning' => 'Чутливе право — перед видачею перевірте уважно.',
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

        'limit' => [
            'reached_title' => 'Досягнуто ліміт асистентів',
            'hint'          => 'Ліміт досягнуто (:current з :limit)',
        ],

        'fields' => [
            'name'                => 'Назва',
            'default_language'    => 'Мова за замовчуванням',
            'available_languages' => 'Доступні мови',
            'is_active'           => 'Активний',
            'fallback_message'    => 'Повідомлення за замовчуванням',
            'default_flow'        => 'Сценарій за замовчуванням',
            'default_flow_help'   => 'Буде доступно після ввімкнення домену Flow.',
            'settings'            => 'Налаштування',
            'settings_key'        => 'Ключ',
            'settings_value'      => 'Значення',
            'settings_add'        => 'Додати',
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
            'webhook_hash_help'    => 'Публічна частина webhook URL, зареєстрованого у провайдера. Змінюється дією «Ротувати webhook hash».',
            'bot_link'             => 'Бот',
            'bot_link_pending'     => 'Очікує реєстрації',
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
            'generate_secret_token'           => 'Згенерувати',
            'select_all'                      => 'Вибрати всі',
            'clear_all'                       => 'Очистити',
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash оновлено',
        ],
    ],

    'tenant_translations' => [
        'label'        => 'Переклад',
        'plural_label' => 'Переклади',
        'navigation'   => 'Переклади',
        'subtitle'     => 'Системні повідомлення та підписи, які бот надсилає користувачам. Системні значення вже заповнені — введіть своє, щоб перевизначити для конкретної мови.',
        'saved'        => 'Переклади збережено',
        'reset_done'   => 'Перевизначення для ключа видалено',

        'fields' => [
            'key'                 => 'Ключ',
            'group'               => 'Група',
            'description'         => 'Опис',
            'locale_default_hint' => 'Системне значення: «:default»',
        ],

        'actions' => [
            'edit'  => 'Редагувати',
            'save'  => 'Зберегти',
            'reset' => 'Скинути до системних',
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

    'dashboard' => [
        'chart' => [
            'flow_activity' => [
                'heading'  => 'Активність сценаріїв (останні 14 днів)',
                'executed' => 'Виконано',
                'failed'   => 'Помилки',
            ],
        ],
        'stats' => [
            'assistants' => [
                'label'       => 'Асистенти',
                'description' => ':count активні',
            ],
            'contacts' => [
                'label'       => 'Контакти',
                'description' => 'Всього зареєстровано',
            ],
            'channels' => [
                'label'       => 'Канали',
                'description' => 'Всього :total, підключено :active',
            ],
            'published_flows' => [
                'label'       => 'Опубліковані сценарії',
                'description' => 'Розгорнуто в продакшн',
            ],
            'waiting_sessions' => [
                'label'       => 'Сесії в очікуванні',
                'description' => 'На паузі або чекають введення',
            ],
            'staff_users' => [
                'label'       => 'Співробітники',
                'description' => 'З доступом до цього тенанта',
            ],
        ],
    ],
];
