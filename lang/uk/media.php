<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Контент',
    'model_label'        => 'Медіафайл',
    'plural_model_label' => 'Медіа',

    'fields' => [
        'name'          => 'Назва',
        'kind'          => 'Тип',
        'size'          => 'Розмір',
        'folder'        => 'Папка',
        'parent_folder' => 'Батьківська папка',
        'mime_type'     => 'MIME-тип',
        'references'    => 'Використовується',
        'uploaded_at'   => 'Завантажено',
        'file'          => 'Файл',
    ],

    'kinds' => [
        'image'    => 'Зображення',
        'video'    => 'Відео',
        'audio'    => 'Аудіо',
        'document' => 'Документ',
        'sticker'  => 'Стікер',
        'other'    => 'Інше',
    ],

    'filters' => [
        'root_folder' => '— Корінь —',
    ],

    'navigation' => [
        'root' => 'Всі файли',
        'back' => 'Назад',
    ],

    'actions' => [
        'upload'        => 'Завантажити файли',
        'create_folder' => 'Створити папку',
        'rename'        => 'Перейменувати',
        'move'          => 'Перемістити',
        'references'    => 'Де використовується',
        'close'         => 'Закрити',
        'download'      => 'Завантажити',
    ],

    'notifications' => [
        'uploaded'       => 'Завантажено файлів: :count.',
        'folder_created' => 'Папку створено.',
    ],

    'references' => [
        'modal_heading' => 'Використання файлу',
        'node'          => 'Нода',
        'empty'         => 'Файл не використовується в жодному потоці.',
        'types'         => [
            'flow_definition' => 'Потік',
        ],
    ],

    'errors' => [
        'has_references' => 'Не можна видалити безповоротно: файл використовується в потоках.',
        'file_too_large' => 'Файл перевищує максимально допустимий розмір :max МБ.',
    ],
];
