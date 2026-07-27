<?php

declare(strict_types=1);

return [
    'label'        => 'Розсилка',
    'plural_label' => 'Розсилки',

    'fields' => [
        'name'         => 'Назва',
        'message'      => 'Повідомлення',
        'message_help' => 'Надсилається одержувачам як є. Підтримується базовий HTML.',
        'target'       => 'Аудиторія',
        'tags'         => 'Теги',
        'segment'      => 'Сегмент',
        'status'       => 'Статус',
        'progress'     => 'Надіслано',
        'failed'       => 'Помилки',
        'created_at'   => 'Створено',
    ],

    'targets' => [
        'all'     => 'Усі контакти',
        'tags'    => 'За тегами',
        'segment' => 'За сегментом',
    ],

    'statuses' => [
        'draft'     => 'Чернетка',
        'running'   => 'Виконується',
        'completed' => 'Завершено',
        'failed'    => 'Помилка',
        'cancelled' => 'Скасовано',
    ],

    'actions' => [
        'send'               => 'Надіслати',
        'send_confirm_title' => 'Надіслати розсилку?',
        'send_confirm_body'  => 'Повідомлення буде доставлено всім досяжним одержувачам обраної аудиторії. Скасувати цю дію неможливо.',
        'cancel'             => 'Скасувати',
    ],

    'notifications' => [
        'started'         => 'Розсилку запущено.',
        'already_started' => 'Розсилку вже запущено.',
        'cancelled'       => 'Розсилку скасовано.',
    ],
];
