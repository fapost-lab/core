<?php

declare(strict_types=1);

return [
    'switcher' => [
        'back_to_admin' => 'В админ-панель',
    ],
    'navigation' => [
        'groups' => [
            'overview' => 'Обзор',
            'channels' => 'Каналы',
            'flow'     => 'Сценарии',
            'settings' => 'Настройки',
        ],
    ],
    'flows' => [
        'label'        => 'Сценарий',
        'plural_label' => 'Сценарии',
        'fields'       => [
            'name'           => 'Название',
            'description'    => 'Описание',
            'group'          => 'Группа',
            'group_name'     => 'Название группы',
            'is_public'      => 'Публичный',
            'is_public_hint' => 'Если выключено, контакт должен иметь auth = true для запуска сценария',
            'is_active'      => 'Активный',
            'updated_at'     => 'Обновлено',
        ],
        'actions' => [
            'open_builder' => 'Открыть конструктор',
            'activate'     => 'Активировать',
            'deactivate'   => 'Деактивировать',
        ],
        'filters' => [
            'group'     => 'Группа',
            'is_active' => 'Активный',
        ],
        'groups' => [
            'ungrouped'            => 'Без группы',
            'create_modal_heading' => 'Создать группу',
        ],
        'delete_guard' => [
            'title' => 'Невозможно удалить',
            'body'  => 'У этого сценария есть активные сессии. Сначала деактивируйте его.',
        ],
    ],
    'flow_groups' => [
        'label'        => 'Группа',
        'plural_label' => 'Группы',
        'fields'       => [
            'name'        => 'Название',
            'flows_count' => 'Сценарии',
        ],
        'delete_guard' => [
            'title' => 'Невозможно удалить',
            'body'  => 'В этой группе есть сценарии. Переместите или удалите их сначала.',
        ],
    ],
    'pages' => [
        'overview' => [
            'title'    => 'Обзор ассистента',
            'sections' => [
                'summary' => 'Сводка',
            ],
            'fields' => [
                'name'   => 'Имя: :name',
                'status' => 'Статус: :active',
            ],
            'status_active'   => 'Активен',
            'status_inactive' => 'Неактивен',
            'channels_intro'  => 'У этого ассистента :count канал(ов). Управляйте каналами в разделе «Каналы».',
            'placeholders'    => [
                'flow'     => 'Конструктор сценариев появится здесь.',
                'settings' => 'Настройки ассистента появятся здесь.',
            ],
        ],
        'settings' => [
            'title'   => 'Настройки',
            'saved'   => 'Настройки сохранены',
            'actions' => [
                'save' => 'Сохранить',
            ],
            'sections' => [
                'general'  => 'Основные',
                'advanced' => 'Дополнительно',
            ],
            'fields' => [
                'default_language'        => 'Язык по умолчанию',
                'default_language_locked' => 'Нельзя изменить, если есть сценарии',
                'default_flow_id'         => 'Сценарий по умолчанию',
                'fallback_message'        => 'Сообщение-заглушка',
                'settings'                => 'Настройки',
            ],
        ],
    ],
];
