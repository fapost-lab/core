<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Дашборд',
        'menu'      => 'Меню',
    ],

    'breadcrumbs' => [
        'admin' => 'Администрирование',
    ],

    'switcher' => [
        'label'         => 'Ассистент',
        'back_to_admin' => 'Назад в админку',
    ],

    'theme' => [
        'label'  => 'Тема',
        'light'  => 'Светлая',
        'dark'   => 'Тёмная',
        'system' => 'Системная',
    ],

    'language' => [
        'label' => 'Язык',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Аккаунт',
        'sign_out' => 'Выйти',
    ],

    'support' => [
        'banner' => 'Вы вошли как поддержка платформы (:name, :email).',
        'leave'  => 'Выйти',
    ],

    'dashboard' => [
        'title'    => 'Обзор ассистента',
        'sections' => [
            'summary'    => 'Сводка',
            'channels'   => 'Каналы',
            'operations' => 'Операции',
        ],
        'name'             => 'Имя: :name',
        'status'           => 'Статус: :active',
        'status_active'    => 'Активен',
        'status_inactive'  => 'Неактивен',
        'channels_intro'   => 'У этого ассистента :count канал(ов). Управляйте каналами в разделе «Каналы».',
        'operations_intro' => 'Диагностика активных и недавних запусков сценариев.',
        'live_sessions'    => 'Активные сессии (:count)',
        'errors_24h'       => 'Ошибки за 24 часа (:count)',
    ],

    // Общее для всех экранов-списков на DataTable из кита.
    'table' => [
        'search'          => 'Поиск',
        'search_hint'     => 'Поиск…',
        'empty'           => 'Пока здесь пусто.',
        'empty_search'    => 'По вашему запросу ничего не найдено.',
        'clear_search'    => 'Очистить поиск',
        'selected'        => 'Выбрано: :count',
        'clear_selection' => 'Снять выбор',
        'select_all'      => 'Выбрать все строки на странице',
        'select_row'      => 'Выбрать: :name',
        'actions'         => 'Действия',
        'sort_by'         => 'Сортировать по полю «:column»',
        'rows_per_page'   => 'Строк на странице',
        'range'           => ':from–:to из :total',
        'page'            => 'Страница :page из :last',
        'previous'        => 'Предыдущая страница',
        'next'            => 'Следующая страница',
    ],

    // Общее для всех экранов-форм на ките.
    'form' => [
        'save'         => 'Сохранить',
        'saving'       => 'Сохранение…',
        'cancel'       => 'Отмена',
        'delete'       => 'Удалить',
        'edit'         => 'Изменить',
        'edit_named'   => 'Изменить: :name',
        'delete_named' => 'Удалить: :name',
    ],

    'contact_groups' => [
        'title'        => 'Группы контактов',
        'description'  => 'Именованные списки аудитории для рассылок.',
        'new'          => 'Новая группа',
        'create_title' => 'Новая группа контактов',
        'edit_title'   => 'Изменение группы контактов',
        'columns'      => [
            'name'        => 'Название',
            'description' => 'Описание',
            'contacts'    => 'Контакты',
            'created_at'  => 'Создано',
        ],
        'fields' => [
            'name'        => 'Название',
            'description' => 'Описание',
        ],
        'search_label'    => 'Поиск по группам',
        'empty'           => 'Групп контактов пока нет.',
        'empty_hint'      => 'Создайте группу, чтобы направлять рассылку на именованную аудиторию.',
        'delete_selected' => 'Удалить выбранные',
        'delete_one'      => [
            'title'       => 'Удалить эту группу?',
            'description' => 'Группа «:name» будет удалена. Контакты останутся, будет снято только их членство в группе.',
        ],
        'delete_many' => [
            'title'       => 'Удалить выбранные группы?',
            'description' => 'Групп к удалению: :count. Контакты останутся, будет снято только их членство.',
        ],
        'created'      => 'Группа создана.',
        'updated'      => 'Группа сохранена.',
        'deleted'      => 'Группа удалена.',
        'deleted_many' => 'Удалено групп: :count.',
    ],
];
