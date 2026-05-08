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
    'sections' => [
        'header'        => 'Ідентичність',
        'profile'       => 'Профіль',
        'from_platform' => 'Від платформи',
        'group_fields'  => '{1} :count поле|[2,4] :count поля|[5,*] :count полів',
    ],
    'placeholders' => [
        'empty' => '—',
    ],
    'filters' => [
        'platform' => 'Канал',
        'language' => 'Мова',
    ],
];
