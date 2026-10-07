<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Персонал',
    ],

    'notifications' => [
        'escalation_subject' => 'Уведомление от :app',
    ],

    'tenant_settings' => [
        'title'      => 'Настройки',
        'navigation' => 'Настройки',
        'subtitle'   => 'Общие настройки, разделяемые всеми ассистентами тенанта.',
        'saved'      => 'Настройки сохранены',
        'tabs'       => [
            'languages'  => 'Языки',
            'runtime'    => 'Исполнение',
            'broadcasts' => 'Рассылки',
        ],
        'sections' => [
            'messaging' => 'Сообщения',
            'flow'      => 'Сценарии',
        ],
        'fields' => [
            'content_base_language'      => 'Базовый язык контента',
            'content_base_language_help' => 'Язык, на котором авторятся сценарии. Нельзя изменить после публикации первого сценария.',
            'available_languages'        => 'Доступные языки',
            'available_languages_help'   => 'Языки, на которые можно перевести контент в редакторе сценариев.',
            'fallback_language'          => 'Резервный язык',
            'fallback_language_help'     => 'Используется, когда у контакта и ассистента не определён язык.',
            'messaging_rate_limit'       => 'Сообщений в минуту (на чат)',
            'broadcast_chunk_size'       => 'Размер чанка рассылки',
            'broadcast_backpressure'     => 'Backpressure для рассылок',
            'flow_session_ttl'           => 'TTL сессии сценария (сек)',
            'max_retry_attempts'         => 'Макс. количество ретраев ноды',
            'flow_fallback_message'      => 'Резервное сообщение сценария',
        ],
        'actions' => [
            'save' => 'Сохранить',
        ],
        'errors' => [
            'fallback_not_in_available' => 'Резервный язык должен быть в списке доступных.',
            'base_lang_locked'          => 'Нельзя изменить базовый язык после публикации сценариев.',
        ],
    ],

    'permission_groups' => [
        'assistants'    => 'Ассистенты',
        'users'         => 'Пользователи',
        'content'       => 'Контент',
        'contacts'      => 'Контакты',
        'conversations' => 'Диалоги',
        'analytics'     => 'Аналитика',
        'system'        => 'Система',
    ],

    'permissions' => [
        'labels' => [
            'manage_assistants'         => 'Управление ассистентами',
            'manage_channels'           => 'Управление каналами',
            'rotate_channel_token'      => 'Ротация webhook-хэша канала',
            'manage_assistant_settings' => 'Настройки ассистента',
            'manage_flow'               => 'Управление сценариями (устаревшее)',
            'manage_flow_definitions'   => 'Создание / редактирование черновиков сценариев',
            'publish_flow'              => 'Публикация сценария',
            'view_flow_sessions'        => 'Просмотр сессий и логов сценариев',
            'manage_flow_groups'        => 'Управление группами сценариев',
            'manage_translations'       => 'Редактирование переводов',
            'manage_broadcast'          => 'Управление рассылками',
            'manage_rag'                => 'Управление RAG / базой знаний',
            'view_media'                => 'Просмотр медиа-библиотеки',
            'manage_media'              => 'Управление медиа-библиотекой',
            'manage_users'              => 'Управление пользователями',
            'manage_roles'              => 'Управление ролями',
            'view_contacts'             => 'Просмотр контактов',
            'manage_contacts'           => 'Управление контактами',
            'view_conversations'        => 'Просмотр диалогов',
            'reply_conversations'       => 'Ответы в диалогах',
            'view_analytics'            => 'Просмотр аналитики',
            'view_system'               => 'Просмотр системы',
            'manage_settings'           => 'Управление настройками',
        ],
        'descriptions' => [
            'rotate_channel_token' => 'Перевыпускает хэш webhook URL. Текущие конфигурации webhook становятся недействительными до перенастройки.',
            'publish_flow'         => 'Переводит черновик в прод. Вступает в силу немедленно для всех входящих сессий.',
            'manage_roles'         => 'Создание и редактирование пользовательских ролей и их наборов прав.',
            'manage_flow'          => 'Устаревшее право. Охватывает все операции со сценариями. Используйте гранулярные права для новых ролей.',
            'view_conversations'   => 'Открывает полные транскрипты переписки контактов с ассистентом, включая медиа.',
            'reply_conversations'  => 'Отправляет сообщения контакту из операторского инбокса от имени ассистента.',
        ],
        'sensitive_warning' => 'Чувствительное право — перед выдачей проверьте внимательно.',
    ],

    'users' => [
        'label'        => 'Пользователь',
        'plural_label' => 'Пользователи',

        'limit' => [
            'reached_title' => 'Достигнут лимит сотрудников',
            'hint'          => 'Лимит достигнут (:current из :limit)',
        ],

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

        'platform_support' => 'Поддержка платформы',

        'actions' => [
            'resend_activation' => 'Отправить активацию повторно',
            'deactivate'        => 'Деактивировать',
            'activate'          => 'Активировать',
        ],
    ],

    'support_access' => [
        'banner'         => 'Вы вошли как поддержка платформы (:name, :email).',
        'leave'          => 'Выйти',
        'invalid_link'   => 'Ссылка входа поддержки недействительна или истекла.',
        'reserved_email' => 'Адреса в домене .invalid зарезервированы.',
        'protected'      => 'Учётную запись поддержки платформы нельзя изменять.',
    ],

    'assistants' => [
        'label'        => 'Ассистент',
        'plural_label' => 'Ассистенты',

        'limit' => [
            'reached_title' => 'Достигнут лимит ассистентов',
            'hint'          => 'Лимит достигнут (:current из :limit)',
        ],

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

        'limit' => [
            'reached_title' => 'Достигнут лимит каналов',
            'hint'          => 'Лимит достигнут (:current из :limit)',
        ],

        'fields' => [
            'type'                 => 'Канал',
            'token'                => 'Токен',
            'secret_token'         => 'Секретный токен',
            'secret_token_help'    => 'Для проверки заголовка Telegram X-Telegram-Bot-Api-Secret-Token.',
            'is_active'            => 'Активен',
            'webhook_hash'         => 'Webhook hash',
            'webhook_hash_help'    => 'Публичная часть webhook URL, зарегистрированного у провайдера. Меняется действием «Ротировать webhook hash».',
            'bot_link'             => 'Бот',
            'bot_link_pending'     => 'Ожидает регистрации',
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
            'generate_secret_token'           => 'Сгенерировать',
            'select_all'                      => 'Выбрать все',
            'clear_all'                       => 'Очистить',
        ],

        'notifications' => [
            'hash_rotated_title' => 'Webhook hash обновлён',
        ],
    ],

    'tenant_translations' => [
        'label'        => 'Перевод',
        'plural_label' => 'Переводы',
        'navigation'   => 'Переводы',
        'subtitle'     => 'Системные сообщения и подписи, которые бот отправляет пользователям. Системные значения уже заполнены — введите своё, чтобы переопределить для конкретного языка.',
        'saved'        => 'Переводы сохранены',
        'reset_done'   => 'Переопределения для ключа удалены',

        'fields' => [
            'key'                 => 'Ключ',
            'group'               => 'Группа',
            'description'         => 'Описание',
            'locale_default_hint' => 'Системное значение: «:default»',
        ],

        'actions' => [
            'edit'  => 'Редактировать',
            'save'  => 'Сохранить',
            'reset' => 'Сбросить к системным',
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
