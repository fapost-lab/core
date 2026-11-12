<?php

declare(strict_types=1);

/*
| Names of the audit log's areas and actions (App\Domains\Audit\Enums). Every key of the enums is here in every
| language; an action missing here shows as its key on the screen. Past or impersonal forms, so they fit any gender.
*/
return [
    'areas' => [
        'assistants' => 'Асистенти',
        'channels'   => 'Канали',
        'flows'      => 'Сценарії',
        'broadcasts' => 'Розсилки',
        'contacts'   => 'Контакти',
        'staff'      => 'Команда',
        'settings'   => 'Налаштування',
        'auth'       => 'Вхід',
    ],

    // Nested by the key's dot: `assistant.created` is `assistant` => `created`, so `__('audit.actions.assistant.created')` finds it.
    'actions' => [
        'assistant' => [
            'created'          => 'Асистента створено',
            'updated'          => 'Асистента змінено',
            'deleted'          => 'Асистента видалено',
            'settings_updated' => 'Налаштування асистента змінено',
        ],
        'channel' => [
            'created'              => 'Канал створено',
            'updated'              => 'Канал змінено',
            'deleted'              => 'Канал видалено',
            'token_rotated'        => 'Токен каналу перевипущено',
            'webhook_hash_rotated' => 'Адресу вебхука каналу перевипущено',
        ],
        'flow' => [
            'created'   => 'Сценарій створено',
            'updated'   => 'Сценарій змінено',
            'deleted'   => 'Сценарій видалено',
            'published' => 'Сценарій опубліковано',
        ],
        'flow_group' => [
            'created' => 'Групу сценаріїв створено',
            'updated' => 'Групу сценаріїв змінено',
            'deleted' => 'Групу сценаріїв видалено',
        ],
        'broadcast' => [
            'created'   => 'Розсилку створено',
            'updated'   => 'Розсилку змінено',
            'deleted'   => 'Розсилку видалено',
            'sent'      => 'Розсилку запущено',
            'cancelled' => 'Розсилку скасовано',
        ],
        'contact' => [
            'updated' => 'Контакт змінено',
        ],
        'contact_group' => [
            'created' => 'Групу контактів створено',
            'updated' => 'Групу контактів змінено',
            'deleted' => 'Групу контактів видалено',
        ],
        'contact_segment' => [
            'created' => 'Сегмент створено',
            'updated' => 'Сегмент змінено',
            'deleted' => 'Сегмент видалено',
        ],
        'user' => [
            'invited'           => 'Користувача запрошено',
            'updated'           => 'Користувача змінено',
            'roles_changed'     => 'Ролі користувача змінено',
            'activated'         => 'Користувача активовано',
            'deactivated'       => 'Користувача деактивовано',
            'deleted'           => 'Користувача видалено',
            'activation_resent' => 'Лист активації надіслано повторно',
        ],
        'role' => [
            'created' => 'Роль створено',
            'updated' => 'Роль змінено',
            'deleted' => 'Роль видалено',
        ],
        'settings' => [
            'updated' => 'Налаштування робочого простору змінено',
        ],
        'workspace' => [
            'deletion_requested' => 'Запрошено видалення робочого простору',
            'deletion_cancelled' => 'Видалення робочого простору скасовано',
        ],
        'auth' => [
            'signed_in'  => 'Вхід до консолі',
            'signed_out' => 'Вихід з консолі',
        ],
    ],
];
