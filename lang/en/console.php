<?php

declare(strict_types=1);

return [
    'navigation' => [
        'dashboard' => 'Dashboard',
        'menu'      => 'Menu',
    ],

    'breadcrumbs' => [
        'admin' => 'Administration',
    ],

    'switcher' => [
        'label'         => 'Assistant',
        'back_to_admin' => 'Back to Admin Panel',
    ],

    'theme' => [
        'label'  => 'Theme',
        'light'  => 'Light',
        'dark'   => 'Dark',
        'system' => 'System',
    ],

    'language' => [
        'label' => 'Language',
        'en'    => 'English',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
    ],

    'user_menu' => [
        'label'    => 'Account',
        'sign_out' => 'Sign out',
    ],

    'support' => [
        'banner' => 'You are signed in as platform support (:name, :email).',
        'leave'  => 'Sign out',
    ],

    'dashboard' => [
        'title'    => 'Assistant overview',
        'sections' => [
            'summary'    => 'Summary',
            'channels'   => 'Channels',
            'operations' => 'Operations',
        ],
        'name'             => 'Name: :name',
        'status'           => 'Status: :active',
        'status_active'    => 'Active',
        'status_inactive'  => 'Inactive',
        'channels_intro'   => 'This assistant has :count channel(s). Manage channels in the Channels section.',
        'operations_intro' => 'Diagnose live and recent flow executions.',
        'live_sessions'    => 'Live sessions (:count)',
        'errors_24h'       => 'Errors 24 h (:count)',
    ],

    // Shared by every list screen built on the kit's DataTable.
    'table' => [
        'search'          => 'Search',
        'search_hint'     => 'Search…',
        'empty'           => 'Nothing here yet.',
        'empty_search'    => 'Nothing matches your search.',
        'clear_search'    => 'Clear search',
        'selected'        => 'Selected: :count',
        'clear_selection' => 'Clear selection',
        'select_all'      => 'Select all rows on this page',
        'select_row'      => 'Select :name',
        'actions'         => 'Actions',
        'sort_by'         => 'Sort by :column',
        'rows_per_page'   => 'Rows per page',
        'range'           => ':from–:to of :total',
        'page'            => 'Page :page of :last',
        'previous'        => 'Previous page',
        'next'            => 'Next page',
    ],

    // Shared by every form screen built on the kit.
    'form' => [
        'save'         => 'Save',
        'saving'       => 'Saving…',
        'cancel'       => 'Cancel',
        'delete'       => 'Delete',
        'edit'         => 'Edit',
        'edit_named'   => 'Edit :name',
        'delete_named' => 'Delete :name',
    ],

    'contact_groups' => [
        'title'        => 'Contact groups',
        'description'  => 'Named audience lists for targeting broadcasts.',
        'new'          => 'New group',
        'create_title' => 'New contact group',
        'edit_title'   => 'Edit contact group',
        'columns'      => [
            'name'        => 'Name',
            'description' => 'Description',
            'contacts'    => 'Contacts',
            'created_at'  => 'Created',
        ],
        'fields' => [
            'name'        => 'Name',
            'description' => 'Description',
        ],
        'search_label'    => 'Search groups',
        'empty'           => 'No contact groups yet.',
        'empty_hint'      => 'Create a group to target a broadcast at a named audience.',
        'delete_selected' => 'Delete selected',
        'delete_one'      => [
            'title'       => 'Delete this group?',
            'description' => 'The group ":name" will be deleted. Its contacts stay, only their membership in the group is removed.',
        ],
        'delete_many' => [
            'title'       => 'Delete the selected groups?',
            'description' => 'Groups to delete: :count. Their contacts stay, only their membership is removed.',
        ],
        'created'      => 'Group created.',
        'updated'      => 'Group saved.',
        'deleted'      => 'Group deleted.',
        'deleted_many' => 'Groups deleted: :count.',
    ],
];
