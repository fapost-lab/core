<?php

declare(strict_types=1);

/*
| Names of the audit log's areas and actions (App\Domains\Audit\Enums). Every key of the enums is here in every
| language; an action missing here shows as its key on the screen. Past or impersonal forms, so they fit any gender.
*/
return [
    'areas' => [
        'assistants' => 'Ассистенты',
        'channels'   => 'Каналы',
        'flows'      => 'Сценарии',
        'broadcasts' => 'Рассылки',
        'contacts'   => 'Контакты',
        'staff'      => 'Команда',
        'settings'   => 'Настройки',
        'auth'       => 'Вход',
    ],

    // Nested by the key's dot: `assistant.created` is `assistant` => `created`, so `__('audit.actions.assistant.created')` finds it.
    'actions' => [
        'assistant' => [
            'created'          => 'Ассистент создан',
            'updated'          => 'Ассистент изменён',
            'deleted'          => 'Ассистент удалён',
            'settings_updated' => 'Настройки ассистента изменены',
        ],
        'channel' => [
            'created'              => 'Канал создан',
            'updated'              => 'Канал изменён',
            'deleted'              => 'Канал удалён',
            'token_rotated'        => 'Токен канала перевыпущен',
            'webhook_hash_rotated' => 'Адрес вебхука канала перевыпущен',
        ],
        'flow' => [
            'created'   => 'Сценарий создан',
            'updated'   => 'Сценарий изменён',
            'deleted'   => 'Сценарий удалён',
            'published' => 'Сценарий опубликован',
        ],
        'flow_group' => [
            'created' => 'Группа сценариев создана',
            'updated' => 'Группа сценариев изменена',
            'deleted' => 'Группа сценариев удалена',
        ],
        'broadcast' => [
            'created'   => 'Рассылка создана',
            'updated'   => 'Рассылка изменена',
            'deleted'   => 'Рассылка удалена',
            'sent'      => 'Рассылка запущена',
            'cancelled' => 'Рассылка отменена',
        ],
        'contact' => [
            'updated' => 'Контакт изменён',
        ],
        'contact_group' => [
            'created' => 'Группа контактов создана',
            'updated' => 'Группа контактов изменена',
            'deleted' => 'Группа контактов удалена',
        ],
        'contact_segment' => [
            'created' => 'Сегмент создан',
            'updated' => 'Сегмент изменён',
            'deleted' => 'Сегмент удалён',
        ],
        'user' => [
            'invited'           => 'Пользователь приглашён',
            'updated'           => 'Пользователь изменён',
            'roles_changed'     => 'Роли пользователя изменены',
            'activated'         => 'Пользователь активирован',
            'deactivated'       => 'Пользователь деактивирован',
            'deleted'           => 'Пользователь удалён',
            'activation_resent' => 'Письмо активации отправлено повторно',
        ],
        'role' => [
            'created' => 'Роль создана',
            'updated' => 'Роль изменена',
            'deleted' => 'Роль удалена',
        ],
        'settings' => [
            'updated' => 'Настройки рабочего пространства изменены',
        ],
        'workspace' => [
            'deletion_requested' => 'Запрошено удаление рабочего пространства',
            'deletion_cancelled' => 'Удаление рабочего пространства отменено',
        ],
        'auth' => [
            'signed_in'  => 'Вход в консоль',
            'signed_out' => 'Выход из консоли',
        ],
    ],
];
