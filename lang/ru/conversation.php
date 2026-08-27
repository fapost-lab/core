<?php

declare(strict_types=1);

return [
    'label'        => 'Диалог',
    'plural_label' => 'Диалоги',

    'fields' => [
        'contact'       => 'Контакт',
        'platform'      => 'Канал',
        'last_message'  => 'Последнее сообщение',
        'unread'        => 'Непрочитанные',
        'messages'      => 'Сообщений',
        'status'        => 'Статус',
        'last_activity' => 'Активность',
    ],

    'statuses' => [
        'open'    => 'Открыт',
        'closed'  => 'Закрыт',
        'snoozed' => 'Отложен',
    ],

    'content_types' => [
        'text'     => 'Текст',
        'photo'    => 'Фото',
        'document' => 'Документ',
        'video'    => 'Видео',
        'voice'    => 'Голосовое',
        'audio'    => 'Аудио',
        'location' => 'Геолокация',
        'contact'  => 'Контакт',
        'callback' => 'Нажатие кнопки',
        'unknown'  => 'Вложение',
    ],

    'delivery' => [
        'received'  => 'Получено',
        'queued'    => 'В очереди',
        'sent'      => 'Отправлено',
        'delivered' => 'Доставлено',
        'read'      => 'Прочитано',
        'failed'    => 'Ошибка',
    ],

    'message_count' => '{0} Нет сообщений|{1} :count сообщение|[2,4] :count сообщения|[5,*] :count сообщений',
    'today'         => 'Сегодня',
    'attachment'    => 'Вложение',
    'empty'         => 'В этом диалоге пока нет сообщений.',
    'load_older'    => 'Загрузить более старые сообщения',

    'owner' => [
        'bot'         => 'Отвечает бот',
        'staff'       => 'Отвечает оператор',
        'staff_named' => 'Отвечает :name',
    ],

    'actions' => [
        'reply'         => 'Ответить',
        'take_over'     => 'Взять диалог',
        'return_to_bot' => 'Вернуть боту',
    ],

    'reply' => [
        'label'       => 'Сообщение',
        'placeholder' => 'Введите ответ контакту…',
    ],

    'notifications' => [
        'reply_sent'          => 'Ответ отправлен.',
        'reply_failed'        => 'Не удалось отправить ответ. Попробуйте ещё раз.',
        'reply_undeliverable' => 'У этого контакта нет активного канала для ответа.',
        'taken_over'          => 'Теперь вы ведёте этот диалог.',
        'returned_to_bot'     => 'Диалог возвращён боту.',
    ],

    'media' => [
        'pending' => 'Загрузка…',
        'failed'  => 'Не удалось загрузить',
    ],
];
