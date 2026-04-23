<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'До адмін-панелі',
    ],
    'navigation' => [
        'groups' => [
            'overview' => 'Огляд',
            'channels' => 'Канали',
            'flow'     => 'Сценарії',
            'settings' => 'Налаштування',
        ],
    ],
    'flows' => [
        'label'        => 'Сценарій',
        'plural_label' => 'Сценарії',
        'fields'       => [
            'name'           => 'Назва',
            'description'    => 'Опис',
            'group'          => 'Група',
            'group_name'     => 'Назва групи',
            'is_public'      => 'Публічний',
            'is_public_hint' => 'Якщо вимкнено, контакт повинен мати auth = true для запуску сценарію',
            'is_active'      => 'Активний',
            'versions'       => 'Версії',
            'updated_at'     => 'Оновлено',
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
            'title' => 'Неможливо видалити',
            'body'  => 'У цього сценарію є активні сесії. Спочатку деактивуйте його.',
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
            'title' => 'Неможливо видалити',
            'body'  => 'У цій групі є сценарії. Спочатку перемістіть або видаліть їх.',
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
        ],
        'settings' => [
            'title'   => 'Налаштування',
            'saved'   => 'Налаштування збережено',
            'actions' => [
                'save' => 'Зберегти',
            ],
            'sections' => [
                'general'  => 'Загальні',
                'advanced' => 'Додатково',
            ],
            'fields' => [
                'default_language'        => 'Мова за замовчуванням',
                'default_language_locked' => "Неможливо змінити, якщо є сценарії",
                'available_languages'     => 'Доступні мови',
                'default_flow_id'         => 'Сценарій за замовчуванням',
                'fallback_message'        => 'Резервне повідомлення',
                'settings'                => 'Налаштування',
            ],
        ],
    ],
];
