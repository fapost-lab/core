<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Контент',
    'model_label'        => 'Медиафайл',
    'plural_model_label' => 'Медиа',

    'fields' => [
        'name'             => 'Имя',
        'kind'             => 'Тип',
        'size'             => 'Размер',
        'folder'           => 'Папка',
        'parent_folder'    => 'Родительская папка',
        'mime_type'        => 'MIME-тип',
        'references'       => 'Используется',
        'uploaded_at'      => 'Загружено',
        'file'             => 'Файл',
        'move_files_to'    => 'Переместить файлы в',
        'move_contents_to' => 'Переместить содержимое в',
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
        'root'       => 'Корень',
        'back'       => 'Назад',
        'no_folders' => 'Папок пока нет',
    ],

    'actions' => [
        'upload'                     => 'Загрузить файлы',
        'create_folder'              => 'Создать папку',
        'delete_folder'              => 'Удалить папку',
        'delete_folder_heading'      => 'Удалить папку',
        'delete_folder_confirm'      => 'Папка пуста. Она будет удалена безвозвратно.',
        'delete_folder_has_files'    => 'В папке :count файл(ов). Выберите, куда их переместить перед удалением.',
        'delete_folder_has_children' => 'В папке :count подпапок. Выберите, куда их переместить перед удалением.',
        'delete_folder_has_both'     => 'В папке :files файл(ов) и :folders подпапок. Выберите, куда переместить содержимое перед удалением.',
        'rename_folder'              => 'Переименовать папку',
        'rename'                     => 'Переименовать',
        'save'                       => 'Сохранить',
        'move'                       => 'Переместить',
        'references'                 => 'Где используется',
        'close'                      => 'Закрыть',
        'download'                   => 'Скачать',
        'cancel'                     => 'Отмена',
        'delete'                     => 'Удалить',
    ],

    'notifications' => [
        'uploaded'       => 'Загружено файлов: :count.',
        'folder_created' => 'Папка создана.',
        'folder_deleted' => 'Папка удалена.',
        'folder_renamed' => 'Папка переименована.',
        'files_moved'    => 'Перемещено файлов: :count.',
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
        'has_references'        => 'Нельзя удалить безвозвратно: файл используется в потоках.',
        'file_too_large'        => 'Файл превышает максимально допустимый размер :max МБ.',
        'storage_limit_title'   => 'Хранилище медиа заполнено',
        'storage_limit_reached' => 'Хранилище медиа заполнено: занято :used из :limit, файлу нужно :needed. Удалите ненужные файлы навсегда (из корзины), чтобы освободить место.',
        'storage_limit_saved'   => 'До достижения лимита сохранено файлов: :saved из :total.',
        'folder'                => [
            'not_found'        => 'Папка не найдена.',
            'parent_not_found' => 'Родительская папка не найдена.',
            'own_subtree'      => 'Папку нельзя переместить внутрь неё самой.',
            'too_deep'         => 'Вложенность папок превысит допустимую (:max).',
        ],
    ],
];
