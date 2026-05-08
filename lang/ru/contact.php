<?php

declare(strict_types=1);

return [
    'label'        => 'Контакт',
    'plural_label' => 'Контакты',
    'navigation'   => [
        'group' => 'Контакты',
    ],
    'fields' => [
        'id'          => 'ID',
        'platform'    => 'Канал',
        'external_id' => 'ID пользователя канала',
        'language'    => 'Язык',
        'name'        => 'Имя',
        'username'    => 'Username',
        'created_at'  => 'Создан',
        'updated_at'  => 'Обновлён',
    ],
    'sections' => [
        'header'        => 'Идентичность',
        'profile'       => 'Профиль',
        'from_platform' => 'От платформы',
        'group_fields'  => '{1} :count поле|[2,4] :count поля|[5,*] :count полей',
    ],
    'placeholders' => [
        'empty' => '—',
    ],
    'filters' => [
        'platform' => 'Канал',
        'language' => 'Язык',
    ],
];
