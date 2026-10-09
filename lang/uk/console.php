<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Дашборд',
        'menu'      => 'Меню',
    ],

    'breadcrumbs' => [
        'admin' => 'Адміністрування',
    ],

    'switcher' => [
        'label'         => 'Асистент',
        'back_to_admin' => 'Назад в адмінку',
    ],

    'theme' => [
        'label'  => 'Тема',
        'light'  => 'Світла',
        'dark'   => 'Темна',
        'system' => 'Системна',
    ],

    'language' => [
        'label' => 'Мова',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Обліковий запис',
        'sign_out' => 'Вийти',
    ],

    'support' => [
        'banner' => 'Ви увійшли як підтримка платформи (:name, :email).',
        'leave'  => 'Вийти',
    ],

    'dashboard' => [
        'title'    => 'Огляд асистента',
        'sections' => [
            'summary'    => 'Підсумок',
            'channels'   => 'Канали',
            'operations' => 'Операції',
        ],
        'name'             => 'Назва: :name',
        'status'           => 'Статус: :active',
        'status_active'    => 'Активний',
        'status_inactive'  => 'Неактивний',
        'channels_intro'   => 'У цього асистента :count канал(ів). Керуйте каналами в розділі «Канали».',
        'operations_intro' => 'Діагностика активних та нещодавніх запусків сценаріїв.',
        'live_sessions'    => 'Активні сесії (:count)',
        'errors_24h'       => 'Помилки за 24 години (:count)',
    ],

    // Спільне для всіх екранів-списків на DataTable з кіта.
    'table' => [
        'search'          => 'Пошук',
        'search_hint'     => 'Пошук…',
        'empty'           => 'Тут поки порожньо.',
        'empty_search'    => 'За вашим запитом нічого не знайдено.',
        'clear_search'    => 'Очистити пошук',
        'selected'        => 'Вибрано: :count',
        'clear_selection' => 'Зняти вибір',
        'select_all'      => 'Вибрати всі рядки на сторінці',
        'select_row'      => 'Вибрати: :name',
        'actions'         => 'Дії',
        'sort_by'         => 'Сортувати за полем «:column»',
        'rows_per_page'   => 'Рядків на сторінці',
        'range'           => ':from–:to з :total',
        'page'            => 'Сторінка :page з :last',
        'previous'        => 'Попередня сторінка',
        'next'            => 'Наступна сторінка',
    ],

    // Спільне для всіх екранів-форм на кіті.
    'form' => [
        'save'         => 'Зберегти',
        'saving'       => 'Збереження…',
        'cancel'       => 'Скасувати',
        'delete'       => 'Видалити',
        'edit'         => 'Змінити',
        'edit_named'   => 'Змінити: :name',
        'delete_named' => 'Видалити: :name',
    ],

    'contact_groups' => [
        'title'        => 'Групи контактів',
        'description'  => 'Іменовані списки аудиторії для розсилок.',
        'new'          => 'Нова група',
        'create_title' => 'Нова група контактів',
        'edit_title'   => 'Зміна групи контактів',
        'columns'      => [
            'name'        => 'Назва',
            'description' => 'Опис',
            'contacts'    => 'Контакти',
            'created_at'  => 'Створено',
        ],
        'fields' => [
            'name'        => 'Назва',
            'description' => 'Опис',
        ],
        'search_label'    => 'Пошук по групах',
        'empty'           => 'Груп контактів поки немає.',
        'empty_hint'      => 'Створіть групу, щоб спрямовувати розсилку на іменовану аудиторію.',
        'delete_selected' => 'Видалити вибрані',
        'delete_one'      => [
            'title'       => 'Видалити цю групу?',
            'description' => 'Групу «:name» буде видалено. Контакти залишаться, буде знято лише їхнє членство в групі.',
        ],
        'delete_many' => [
            'title'       => 'Видалити вибрані групи?',
            'description' => 'Груп до видалення: :count. Контакти залишаться, буде знято лише їхнє членство.',
        ],
        'created'      => 'Групу створено.',
        'updated'      => 'Групу збережено.',
        'deleted'      => 'Групу видалено.',
        'deleted_many' => 'Видалено груп: :count.',
    ],
];
