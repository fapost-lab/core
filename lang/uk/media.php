<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Контент',
    'model_label'        => 'Медіафайл',
    'plural_model_label' => 'Медіа',

    'fields' => [
        'name'             => 'Назва',
        'kind'             => 'Тип',
        'size'             => 'Розмір',
        'folder'           => 'Папка',
        'parent_folder'    => 'Батьківська папка',
        'mime_type'        => 'MIME-тип',
        'references'       => 'Використовується',
        'uploaded_at'      => 'Завантажено',
        'file'             => 'Файл',
        'move_files_to'    => 'Перемістити файли до',
        'move_contents_to' => 'Перемістити вміст до',
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
        'root'       => 'Корінь',
        'back'       => 'Назад',
        'no_folders' => 'Папок поки немає',
    ],

    'actions' => [
        'upload'                     => 'Завантажити файли',
        'create_folder'              => 'Створити папку',
        'delete_folder'              => 'Видалити папку',
        'delete_folder_heading'      => 'Видалити папку',
        'delete_folder_confirm'      => 'Папка порожня. Її буде видалено назавжди.',
        'delete_folder_has_files'    => 'У папці :count файл(ів). Виберіть, куди перемістити їх перед видаленням.',
        'delete_folder_has_children' => 'У папці :count підпапок. Виберіть, куди перемістити їх перед видаленням.',
        'delete_folder_has_both'     => 'У папці :files файл(ів) та :folders підпапок. Виберіть, куди перемістити вміст перед видаленням.',
        'rename_folder'              => 'Перейменувати папку',
        'rename'                     => 'Перейменувати',
        'save'                       => 'Зберегти',
        'move'                       => 'Перемістити',
        'references'                 => 'Де використовується',
        'close'                      => 'Закрити',
        'download'                   => 'Завантажити',
        'cancel'                     => 'Скасувати',
        'delete'                     => 'Видалити',
    ],

    'notifications' => [
        'uploaded'       => 'Завантажено файлів: :count.',
        'folder_created' => 'Папку створено.',
        'folder_deleted' => 'Папку видалено.',
        'folder_renamed' => 'Папку перейменовано.',
        'files_moved'    => 'Переміщено файлів: :count.',
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
        'has_references'        => 'Не можна видалити безповоротно: файл використовується в потоках.',
        'file_too_large'        => 'Файл перевищує максимально допустимий розмір :max МБ.',
        'storage_limit_title'   => 'Сховище медіа заповнене',
        'storage_limit_reached' => 'Сховище медіа заповнене: зайнято :used з :limit, файлу потрібно :needed. Видаліть непотрібні файли назавжди (з кошика), щоб звільнити місце.',
        'storage_limit_saved'   => 'До досягнення ліміту збережено файлів: :saved з :total.',
    ],
];
