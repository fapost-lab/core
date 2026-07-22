<?php

declare(strict_types=1);

return [
    'label'        => 'Діалог',
    'plural_label' => 'Діалоги',

    'fields' => [
        'contact'       => 'Контакт',
        'platform'      => 'Канал',
        'last_message'  => 'Останнє повідомлення',
        'unread'        => 'Непрочитані',
        'messages'      => 'Повідомлень',
        'status'        => 'Статус',
        'last_activity' => 'Активність',
    ],

    'statuses' => [
        'open'    => 'Відкритий',
        'closed'  => 'Закритий',
        'snoozed' => 'Відкладений',
    ],

    'content_types' => [
        'text'     => 'Текст',
        'photo'    => 'Фото',
        'document' => 'Документ',
        'video'    => 'Відео',
        'voice'    => 'Голосове',
        'audio'    => 'Аудіо',
        'location' => 'Геолокація',
        'contact'  => 'Контакт',
        'callback' => 'Натискання кнопки',
        'unknown'  => 'Вкладення',
    ],

    'delivery' => [
        'received'  => 'Отримано',
        'queued'    => 'У черзі',
        'sent'      => 'Надіслано',
        'delivered' => 'Доставлено',
        'read'      => 'Прочитано',
        'failed'    => 'Помилка',
    ],

    'message_count' => '{0} Немає повідомлень|{1} :count повідомлення|[2,4] :count повідомлення|[5,*] :count повідомлень',
    'today'         => 'Сьогодні',
    'attachment'    => 'Вкладення',
    'empty'         => 'У цьому діалозі поки немає повідомлень.',
];
