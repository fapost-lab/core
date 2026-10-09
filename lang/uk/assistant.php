<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'До адмін-панелі',
    ],
    'navigation' => [
        'groups' => [
            'overview'   => 'Огляд',
            'channels'   => 'Канали',
            'flow'       => 'Сценарії',
            'operations' => 'Експлуатація',
            'settings'   => 'Налаштування',
        ],
    ],
    'flows' => [
        'label'        => 'Сценарій',
        'plural_label' => 'Сценарії',

        'limit' => [
            'reached_title' => 'Досягнуто ліміт сценаріїв',
            'hint'          => 'Ліміт досягнуто (:current з :limit)',
        ],

        'fields' => [
            'name'                 => 'Назва',
            'description'          => 'Опис',
            'group'                => 'Група',
            'group_name'           => 'Назва групи',
            'is_public'            => 'Публічний',
            'is_public_hint'       => 'Якщо вимкнено, контакт повинен мати auth = true для запуску сценарію',
            'is_active'            => 'Активний',
            'logging_enabled'      => 'Логувати історію змін',
            'logging_enabled_hint' => 'Зберігає всі відповіді користувача та зміни даних до історії сесії для подальшого аналізу.',
            'versions'             => 'Версії',
            'updated_at'           => 'Оновлено',
        ],
        'actions' => [
            'open_builder' => 'Конструктор',
            'activate'     => 'Активувати',
            'deactivate'   => 'Вимкнути',
        ],
        'filters' => [
            'group'     => 'Група',
            'is_active' => 'Активний',
        ],
        'visibility' => [
            'public'  => 'Публічний',
            'private' => 'Приватний',
        ],
        'groups' => [
            'ungrouped'            => 'Без групи',
            'create_modal_heading' => 'Створити групу',
        ],
        'delete_guard' => [
            'title'   => 'Неможливо видалити',
            'body'    => 'У цього сценарію є активні сесії. Спочатку деактивуйте його.',
            'skipped' => 'Пропущено, бо в них є активні сесії: :count.',
        ],
        'trigger_summary' => [
            'message'  => 'Повідомлення: ключові слова :keywords; фрази :phrases',
            'event'    => 'Подія: :event',
            'schedule' => 'Розклад: :cron (:timezone)',
            'webhook'  => 'Вебхук: :method :path',
            'api'      => 'API: :route_key; джерела :allowed_sources',
            'unknown'  => 'Тригер налаштовано',
        ],
    ],
    'flow_groups' => [
        'label'        => 'Група',
        'plural_label' => 'Групи',
        'fields'       => [
            'name'        => 'Назва',
            'flows_count' => 'Сценарії',
        ],
        'delete_guard' => [
            'title'   => 'Неможливо видалити',
            'body'    => 'У цій групі є сценарії. Спочатку перемістіть або видаліть їх.',
            'skipped' => 'Пропущено, бо в них є сценарії: :count.',
        ],
    ],
    'pages' => [
        'overview' => [
            'title'    => 'Огляд асистента',
            'sections' => [
                'summary' => 'Підсумок',
            ],
            'fields' => [
                'name'   => 'Назва: :name',
                'status' => 'Статус: :active',
            ],
            'status_active'   => 'Активний',
            'status_inactive' => 'Неактивний',
            'channels_intro'  => 'У цього асистента :count канал(ів). Керуйте каналами в розділі «Канали».',
            'placeholders'    => [
                'flow'     => "Конструктор сценаріїв з'явиться тут.",
                'settings' => "Налаштування асистента з'являться тут.",
            ],
            'operations' => [
                'intro'         => 'Діагностика активних та нещодавніх запусків сценаріїв.',
                'live_sessions' => 'Активні сесії (:count)',
                'errors_24h'    => 'Помилки за 24 години (:count)',
            ],
        ],
        'settings' => [
            'title'   => 'Налаштування',
            'saved'   => 'Налаштування збережено',
            'actions' => [
                'save' => 'Зберегти',
            ],
            'tabs' => [
                'general'  => 'Загальні',
                'commands' => 'Команди',
                'advanced' => 'Додатково',
            ],
            'fields' => [
                'default_language'           => 'Мова за замовчуванням',
                'default_language_locked'    => 'Неможливо змінити, якщо є сценарії',
                'available_languages'        => 'Доступні мови',
                'available_countries'        => 'Країни обслуговування',
                'available_countries_help'   => 'Країни, для яких працює асистент — визначають формати телефонів і валідацію в input-нодах.',
                'default_flow_id'            => 'Сценарій за замовчуванням',
                'default_flow_create'        => 'Створити новий сценарій',
                'default_flow_create_modal'  => 'Створення нового сценарію',
                'default_flow_create_submit' => 'Створити і відкрити в конструкторі',
                'fallback_message'           => 'Резервне повідомлення',
                'busy_message'               => 'Повідомлення «бот зайнятий»',
                'busy_message_help'          => 'Надсилається, коли бот обробляє попереднє повідомлення. Якщо залишити порожнім — використовується системний переклад.',
                'settings'                   => 'Налаштування',
                'commands'                   => 'Команди',
                'commands_help'              => 'Слеш-команди, доступні в усіх сценаріях. Вбудовані (/reset, /cancel) можна перевизначити лише в частині тексту відповіді.',
            ],
            'commands' => [
                'add_label'  => 'Додати команду',
                'item_label' => 'Команда',
                'fields'     => [
                    'command'       => 'Команда',
                    'type'          => 'Дія',
                    'response'      => 'Підтвердження',
                    'response_help' => 'Необов’язкове повідомлення після виконання дії.',
                    'flow_id'       => 'Запустити сценарій',
                    'text'          => 'Повідомлення',
                ],
                'types' => [
                    'terminate_session' => 'Завершити сесію',
                    'start_flow'        => 'Запустити сценарій',
                    'send_message'      => 'Надіслати повідомлення',
                ],
                'types_help' => [
                    'terminate_session' => 'Завершує поточний діалог без запуску нового. Використовується для команд аварійного виходу на кшталт /cancel.',
                    'start_flow'        => 'Завершує поточний діалог і запускає обраний сценарій.',
                    'send_message'      => 'Надсилає інформаційну відповідь, не зачіпаючи поточний діалог.',
                ],
            ],
            'errors' => [
                'commands_invalid' => 'Команди некоректні: :error',
            ],
        ],
    ],
    'flow_sessions' => [
        'label'        => 'Сесія',
        'plural_label' => 'Сесії',
        'fields'       => [
            'id'              => 'Сесія',
            'contact'         => 'Контакт',
            'flow'            => 'Сценарій',
            'status'          => 'Статус',
            'end_status'      => 'Фінальний статус',
            'current_node_id' => 'Поточний вузол',
            'parent'          => 'Батьківська сесія',
            'state'           => 'Стан',
            'version'         => 'Версія',
            'expires_at'      => 'Закінчується',
            'created_at'      => 'Початок',
            'updated_at'      => 'Оновлення',
            'history'         => 'Історія',
        ],
        'history' => [
            'timestamp' => 'Коли',
            'node'      => 'Вузол',
            'event'     => 'Подія',
            'path'      => 'Шлях',
            'payload'   => 'Дані',
        ],
        'statuses' => [
            'pending'            => 'Очікування',
            'active'             => 'Активна',
            'waiting_input'      => 'Чекає введення',
            'paused'             => 'Пауза',
            'paused_subflow'     => 'Пауза (підсценарій)',
            'completed'          => 'Завершена',
            'ended'              => 'Закінчена',
            'failed'             => 'Збій',
            'cancelled'          => 'Скасована',
            'expired'            => 'Прострочена',
            'terminated_by_user' => 'Перервана користувачем',
        ],
        'filters' => [
            'live_only'    => 'Лише живі',
            'status'       => 'Статус',
            'flow'         => 'Сценарій',
            'created_from' => 'Від',
            'created_to'   => 'До',
        ],
    ],
    'flow_logs' => [
        'label'        => 'Лог',
        'plural_label' => 'Логи сценаріїв',
        'fields'       => [
            'created_at'    => 'Час',
            'session_id'    => 'Сесія',
            'node_id'       => 'Вузол',
            'node_type'     => 'Тип',
            'node_version'  => 'V',
            'status'        => 'Статус',
            'source_handle' => 'Вихід',
            'state_changes' => 'Зміни',
            'resolved'      => 'Резолвінг',
            'error'         => 'Помилка',
        ],
        'statuses' => [
            'executed' => 'Виконано',
            'waiting'  => 'Чекає',
            'failed'   => 'Збій',
            'terminal' => 'Термінал',
        ],
        'filters' => [
            'session_id' => 'Сесія',
            'node_type'  => 'Тип вузла',
            'status'     => 'Статус',
            'has_error'  => 'Лише помилки',
            'from'       => 'Від',
            'to'         => 'До',
        ],
    ],
];
