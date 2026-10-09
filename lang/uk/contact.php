<?php

declare(strict_types=1);

return [
    'label'        => 'Контакт',
    'plural_label' => 'Контакти',
    'navigation'   => [
        'group' => 'Контакти',
    ],
    'fields' => [
        'id'          => 'ID',
        'platform'    => 'Канал',
        'external_id' => 'ID користувача каналу',
        'language'    => 'Мова',
        'name'        => 'Імʼя',
        'username'    => 'Username',
        'created_at'  => 'Створено',
        'updated_at'  => 'Оновлено',
    ],
    'tags' => [
        'label'  => 'Теги',
        'manage' => 'Керування тегами',
    ],
    'groups' => [
        'label'  => 'Групи',
        'manage' => 'Керування групами',
    ],
    'sections' => [
        'header'        => 'Ідентичність',
        'profile'       => 'Профіль',
        'from_platform' => 'Від платформи',
        'group_fields'  => ':count поле|:count поля|:count полів',
    ],
    'values' => [
        'yes' => 'Так',
        'no'  => 'Ні',
    ],
    'placeholders' => [
        'empty' => '—',
    ],
    'filters' => [
        'platform' => 'Канал',
        'language' => 'Мова',
    ],
];
