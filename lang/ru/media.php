<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Контент',
    'model_label'        => 'Медиафайл',
    'plural_model_label' => 'Медиа',

    'fields' => [
        'name'          => 'Имя',
        'kind'          => 'Тип',
        'size'          => 'Размер',
        'folder'        => 'Папка',
        'parent_folder' => 'Родительская папка',
        'mime_type'     => 'MIME-тип',
        'references'    => 'Используется',
        'uploaded_at'   => 'Загружено',
        'file'          => 'Файл',
    ],

    'kinds' => [
        'image'    => 'Изображение',
        'video'    => 'Видео',
        'audio'    => 'Аудио',
        'document' => 'Документ',
        'sticker'  => 'Стикер',
        'other'    => 'Другое',
    ],

    'filters' => [
        'root_folder' => '— Корень —',
    ],

    'navigation' => [
        'root' => 'Все файлы',
        'back' => 'Назад',
    ],

    'actions' => [
        'upload'        => 'Загрузить файлы',
        'create_folder' => 'Создать папку',
        'rename'        => 'Переименовать',
        'move'          => 'Переместить',
        'references'    => 'Где используется',
        'close'         => 'Закрыть',
        'download'      => 'Скачать',
    ],

    'notifications' => [
        'uploaded'       => 'Загружено файлов: :count.',
        'folder_created' => 'Папка создана.',
    ],

    'references' => [
        'modal_heading' => 'Использование файла',
        'node'          => 'Нода',
        'empty'         => 'Файл не используется ни в одном потоке.',
        'types'         => [
            'flow_definition' => 'Поток',
        ],
    ],

    'errors' => [
        'has_references' => 'Нельзя удалить безвозвратно: файл используется в потоках.',
        'file_too_large' => 'Файл превышает максимально допустимый размер :max МБ.',
    ],
];
