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
    'tags' => [
        'label'  => 'Теги',
        'manage' => 'Управление тегами',
    ],
    'groups' => [
        'label'  => 'Группы',
        'manage' => 'Управление группами',
    ],
    'sections' => [
        'header'        => 'Идентичность',
        'profile'       => 'Профиль',
        'from_platform' => 'От платформы',
        'group_fields'  => ':count поле|:count поля|:count полей',
    ],
    'values' => [
        'yes' => 'Да',
        'no'  => 'Нет',
    ],
    'placeholders' => [
        'empty' => '—',
    ],
    'filters' => [
        'platform' => 'Канал',
        'language' => 'Язык',
    ],
];
