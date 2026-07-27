<?php

declare(strict_types=1);

return [
    'label'        => 'Рассылка',
    'plural_label' => 'Рассылки',

    'fields' => [
        'name'         => 'Название',
        'message'      => 'Сообщение',
        'message_help' => 'Отправляется получателям как есть. Поддерживается базовый HTML.',
        'target'       => 'Аудитория',
        'tags'         => 'Теги',
        'segment'      => 'Сегмент',
        'status'       => 'Статус',
        'progress'     => 'Отправлено',
        'failed'       => 'Ошибки',
        'created_at'   => 'Создана',
    ],

    'targets' => [
        'all'     => 'Все контакты',
        'tags'    => 'По тегам',
        'segment' => 'По сегменту',
    ],

    'statuses' => [
        'draft'     => 'Черновик',
        'running'   => 'Выполняется',
        'completed' => 'Завершена',
        'failed'    => 'Ошибка',
        'cancelled' => 'Отменена',
    ],

    'actions' => [
        'send'               => 'Отправить',
        'send_confirm_title' => 'Отправить рассылку?',
        'send_confirm_body'  => 'Сообщение будет доставлено всем достижимым получателям выбранной аудитории. Отменить это действие нельзя.',
        'cancel'             => 'Отменить',
    ],

    'notifications' => [
        'started'         => 'Рассылка запущена.',
        'already_started' => 'Рассылка уже запущена.',
        'cancelled'       => 'Рассылка отменена.',
    ],
];
