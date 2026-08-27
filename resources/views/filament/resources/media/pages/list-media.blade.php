<x-filament-panels::page>
    <div class="media-manager">

        {{-- ── Left: folder tree ─────────────────────────────────────────────── --}}
        <aside class="media-tree">

            {{-- Root --}}
            <button
                type="button"
                wire:click="navigateToFolder(null)"
                @class([
                    'media-tree-item media-tree-item--root',
                    'media-tree-item--active' => $this->currentFolderId === null,
                ])
            >
                <svg class="media-tree-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                </svg>
                {{ __('media.navigation.root') }}
            </button>

            {{-- Folder list ordered by path, indented by depth --}}
            @foreach ($folderTree as $folder)
                <div class="media-tree-entry" style="padding-left: {{ $folder['depth'] * 1 }}rem;">
                    <button
                        type="button"
                        wire:click="navigateToFolder('{{ $folder['id'] }}')"
                        @class([
                            'media-tree-item',
                            'media-tree-item--active' => $this->currentFolderId === $folder['id'],
                        ])
                    >
                        <svg class="media-tree-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z"/>
                        </svg>
                        <span class="media-tree-label">{{ $folder['name'] }}</span>
                    </button>

                    @can(\App\Domains\Staff\Enums\Permission::ManageMedia->value)
                        <button
                            type="button"
                            wire:click="initRenameFolder('{{ $folder['id'] }}')"
                            class="media-tree-action media-tree-rename"
                            title="{{ __('media.actions.rename_folder') }}"
                        >
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            wire:click="initDeleteFolder('{{ $folder['id'] }}')"
                            class="media-tree-action media-tree-delete"
                            title="{{ __('media.actions.delete_folder') }}"
                        >
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    @endcan
                </div>
            @endforeach

            @if (count($folderTree) === 0)
                <p class="media-tree-empty">{{ __('media.navigation.no_folders') }}</p>
            @endif

        </aside>

        {{-- ── Right: breadcrumbs + table ────────────────────────────────────── --}}
        <div class="media-content">

            {{-- Breadcrumb trail --}}
            @if (count($folderBreadcrumbs) > 0)
                <nav class="media-breadcrumbs">
                    <button type="button" wire:click="navigateToFolder(null)" class="media-breadcrumbs-crumb">
                        {{ __('media.navigation.root') }}
                    </button>

                    @foreach ($folderBreadcrumbs as $crumb)
                        <span class="media-breadcrumbs-sep">/</span>
                        @if ($loop->last)
                            <span class="media-breadcrumbs-crumb media-breadcrumbs-crumb--active">{{ $crumb['name'] }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="navigateToFolder('{{ $crumb['id'] }}')"
                                class="media-breadcrumbs-crumb"
                            >{{ $crumb['name'] }}</button>
                        @endif
                    @endforeach
                </nav>
            @endif

            {{-- Files table --}}
            {{ $this->content }}

        </div>

    </div>

    {{-- ── Rename folder dialog ───────────────────────────────────────────────── --}}
    <div
        x-data="{ open: false }"
        x-on:open-rename-folder-dialog.window="open = true; $nextTick(() => $refs.renameInput.select())"
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        class="mdf-overlay"
        @click.self="open = false"
        role="dialog"
        aria-modal="true"
    >
        <div class="mdf-window" @click.stop>

            <div class="mdf-header">
                <div class="mdf-icon mdf-icon--primary">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="mdf-title">{{ __('media.actions.rename_folder') }}</h3>
                    <p class="mdf-folder-name">{{ $this->renameFolderName }}</p>
                </div>
            </div>

            <div class="mdf-body">
                <div class="mdf-field">
                    <label class="mdf-label" for="mdf-rename-input">
                        {{ __('media.fields.name') }}
                    </label>
                    <input
                        id="mdf-rename-input"
                        type="text"
                        x-ref="renameInput"
                        wire:model="renameFolderName"
                        class="mdf-input"
                        x-on:keydown.enter="$wire.executeRenameFolder(); open = false"
                    />
                </div>
            </div>

            <div class="mdf-footer">
                <button
                    type="button"
                    class="mdf-btn mdf-btn--cancel"
                    @click="open = false"
                >{{ __('media.actions.cancel') }}</button>
                <button
                    type="button"
                    class="mdf-btn mdf-btn--primary"
                    wire:click="executeRenameFolder"
                    @click="open = false"
                >{{ __('media.actions.save') }}</button>
            </div>

        </div>
    </div>

    {{-- ── Delete folder dialog ───────────────────────────────────────────────── --}}
    <div
        x-data="{ open: false }"
        x-on:open-delete-folder-dialog.window="open = true"
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        class="mdf-overlay"
        @click.self="open = false"
        role="dialog"
        aria-modal="true"
    >
        <div class="mdf-window" @click.stop>

            {{-- Header --}}
            <div class="mdf-header">
                <div class="mdf-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                </div>
                <div>
                    <h3 class="mdf-title">{{ __('media.actions.delete_folder_heading') }}</h3>
                    <p class="mdf-folder-name">{{ $this->deleteFolderName }}</p>
                </div>
            </div>

            {{-- Body --}}
            @php
                $hasContents = $this->deleteFolderFiles > 0 || $this->deleteFolderChildren > 0;
            @endphp

            @if ($hasContents)
                <div class="mdf-body">
                    <p class="mdf-desc">
                        @if ($this->deleteFolderFiles > 0 && $this->deleteFolderChildren > 0)
                            {{ __('media.actions.delete_folder_has_both', [
                                'files'    => $this->deleteFolderFiles,
                                'folders'  => $this->deleteFolderChildren,
                            ]) }}
                        @elseif ($this->deleteFolderFiles > 0)
                            {{ __('media.actions.delete_folder_has_files', ['count' => $this->deleteFolderFiles]) }}
                        @else
                            {{ __('media.actions.delete_folder_has_children', ['count' => $this->deleteFolderChildren]) }}
                        @endif
                    </p>
                    <div class="mdf-field">
                        <label class="mdf-label" for="mdf-move-to">
                            {{ __('media.fields.move_contents_to') }}
                        </label>
                        <select
                            id="mdf-move-to"
                            wire:model="deleteMoveToId"
                            class="mdf-select"
                        >
                            <option value="">{{ __('media.filters.root_folder') }}</option>
                            @foreach ($this->folderOptionsForDelete() as $id => $path)
                                <option value="{{ $id }}">{{ $path }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @else
                <div class="mdf-body">
                    <p class="mdf-desc">{{ __('media.actions.delete_folder_confirm') }}</p>
                </div>
            @endif

            {{-- Footer --}}
            <div class="mdf-footer">
                <button
                    type="button"
                    class="mdf-btn mdf-btn--cancel"
                    @click="open = false"
                >{{ __('media.actions.cancel') }}</button>
                <button
                    type="button"
                    class="mdf-btn mdf-btn--danger"
                    wire:click="executeDeleteFolder"
                    @click="open = false"
                >{{ __('media.actions.delete') }}</button>
            </div>

        </div>
    </div>

    @push('styles')
    <style>
        .media-manager {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }

        /* ── Tree sidebar ── */
        .media-tree {
            width: 220px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 1px;
            background: var(--color-gray-100, #f3f4f6);
            border: 1px solid var(--color-gray-200, #e5e7eb);
            border-radius: 0.5rem;
            padding: 0.375rem;
            position: sticky;
            top: 1rem;
        }

        .dark .media-tree {
            background: var(--color-gray-800, #1f2937);
            border-color: var(--color-gray-700, #374151);
        }

        /* Wrapper that hosts both nav button + delete button */
        .media-tree-entry {
            position: relative;
            display: flex;
            align-items: center;
        }

        .media-tree-entry:hover .media-tree-action {
            opacity: 1;
        }

        .media-tree-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            min-width: 0;
            text-align: left;
            padding: 0.375rem 3rem 0.375rem 0.75rem; /* right padding for 2 action buttons */
            border-radius: 0.375rem;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.8125rem;
            color: var(--color-gray-600, #4b5563);
            transition: background 0.1s, color 0.1s;
        }

        .dark .media-tree-item {
            color: var(--color-gray-400, #9ca3af);
        }

        .media-tree-item:hover {
            background: var(--color-gray-200, #e5e7eb);
            color: var(--color-gray-900, #111827);
        }

        .dark .media-tree-item:hover {
            background: var(--color-gray-700, #374151);
            color: var(--color-gray-100, #f3f4f6);
        }

        .media-tree-item--active {
            background: var(--color-primary-50, #fffbeb);
            color: var(--color-primary-700, #b45309);
            font-weight: 600;
        }

        .dark .media-tree-item--active {
            background: var(--color-primary-900, #78350f);
            color: var(--color-primary-200, #fde68a);
        }

        .media-tree-item--root {
            font-weight: 500;
            border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
            border-radius: 0.375rem 0.375rem 0 0;
            margin-bottom: 0.25rem;
            padding-bottom: 0.5rem;
            padding-right: 0.75rem; /* no delete button */
        }

        .dark .media-tree-item--root {
            border-bottom-color: var(--color-gray-700, #374151);
        }

        .media-tree-icon {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
        }

        .media-tree-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Folder action buttons (rename + delete), shown on hover */
        .media-tree-action {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 1.25rem;
            height: 1.25rem;
            border: none;
            background: transparent;
            border-radius: 0.25rem;
            cursor: pointer;
            color: var(--color-gray-400, #9ca3af);
            opacity: 0;
            transition: opacity 0.1s, color 0.1s, background 0.1s;
            flex-shrink: 0;
            padding: 0;
        }

        .media-tree-action svg {
            width: 0.75rem;
            height: 0.75rem;
        }

        .media-tree-rename { right: 1.625rem; }
        .media-tree-delete { right: 0.25rem; }

        .media-tree-rename:hover {
            color: var(--color-primary-600, #d97706);
            background: var(--color-primary-50, #fffbeb);
        }

        .dark .media-tree-rename:hover {
            background: var(--color-primary-900, #78350f);
            color: var(--color-primary-300, #fcd34d);
        }

        .media-tree-delete:hover {
            color: var(--color-danger-600, #dc2626);
            background: var(--color-danger-50, #fef2f2);
        }

        .dark .media-tree-delete:hover {
            background: var(--color-danger-900, #7f1d1d);
            color: var(--color-danger-300, #fca5a5);
        }

        .media-tree-empty {
            font-size: 0.75rem;
            color: var(--color-gray-400, #9ca3af);
            padding: 0.5rem 0.75rem;
            margin: 0;
        }

        /* ── Content area ── */
        .media-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        /* ── Breadcrumbs ── */
        .media-breadcrumbs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8125rem;
        }

        .media-breadcrumbs-crumb {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
            color: var(--color-gray-500, #6b7280);
            transition: color 0.1s;
        }

        .media-breadcrumbs-crumb:hover {
            color: var(--color-gray-900, #111827);
        }

        .media-breadcrumbs-crumb--active {
            color: var(--color-gray-900, #111827);
            font-weight: 600;
            cursor: default;
        }

        .dark .media-breadcrumbs-crumb--active {
            color: var(--color-gray-100, #f3f4f6);
        }

        .media-breadcrumbs-sep {
            color: var(--color-gray-300, #d1d5db);
            user-select: none;
        }

        /* ── Delete folder dialog ── */
        [x-cloak] { display: none !important; }

        .mdf-overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        .mdf-window {
            background: var(--color-white, #fff);
            border-radius: 0.75rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            margin: 1rem;
            overflow: hidden;
        }

        .dark .mdf-window {
            background: var(--color-gray-800, #1f2937);
        }

        .mdf-header {
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
            padding: 1.25rem 1.25rem 0;
        }

        .mdf-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background: var(--color-danger-50, #fef2f2);
            color: var(--color-danger-600, #dc2626);
            flex-shrink: 0;
        }

        .dark .mdf-icon {
            background: var(--color-danger-900, #7f1d1d);
            color: var(--color-danger-400, #f87171);
        }

        .mdf-icon--primary {
            background: var(--color-primary-50, #fffbeb);
            color: var(--color-primary-600, #d97706);
        }

        .dark .mdf-icon--primary {
            background: var(--color-primary-900, #78350f);
            color: var(--color-primary-400, #fbbf24);
        }

        .mdf-icon svg {
            width: 1.25rem;
            height: 1.25rem;
        }

        .mdf-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-gray-900, #111827);
            margin: 0 0 0.125rem;
        }

        .dark .mdf-title {
            color: var(--color-gray-100, #f3f4f6);
        }

        .mdf-folder-name {
            font-size: 0.875rem;
            color: var(--color-gray-500, #6b7280);
            margin: 0;
        }

        .mdf-body {
            padding: 1rem 1.25rem;
        }

        .mdf-desc {
            font-size: 0.875rem;
            color: var(--color-gray-600, #4b5563);
            margin: 0 0 0.875rem;
        }

        .dark .mdf-desc {
            color: var(--color-gray-400, #9ca3af);
        }

        .mdf-field {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .mdf-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--color-gray-700, #374151);
        }

        .dark .mdf-label {
            color: var(--color-gray-300, #d1d5db);
        }

        .mdf-select {
            width: 100%;
            padding: 0.4375rem 0.75rem;
            border: 1px solid var(--color-gray-300, #d1d5db);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            color: var(--color-gray-900, #111827);
            background: var(--color-white, #fff);
            outline: none;
            cursor: pointer;
        }

        .mdf-select:focus {
            border-color: var(--color-primary-500, #f59e0b);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }

        .dark .mdf-select {
            background: var(--color-gray-700, #374151);
            border-color: var(--color-gray-600, #4b5563);
            color: var(--color-gray-100, #f3f4f6);
        }

        .mdf-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            padding: 0.875rem 1.25rem;
            border-top: 1px solid var(--color-gray-100, #f3f4f6);
        }

        .dark .mdf-footer {
            border-top-color: var(--color-gray-700, #374151);
        }

        .mdf-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.4375rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: 1px solid transparent;
            cursor: pointer;
            transition: background 0.1s, border-color 0.1s;
        }

        .mdf-btn--cancel {
            background: transparent;
            border-color: var(--color-gray-300, #d1d5db);
            color: var(--color-gray-700, #374151);
        }

        .mdf-btn--cancel:hover {
            background: var(--color-gray-50, #f9fafb);
        }

        .dark .mdf-btn--cancel {
            border-color: var(--color-gray-600, #4b5563);
            color: var(--color-gray-300, #d1d5db);
        }

        .dark .mdf-btn--cancel:hover {
            background: var(--color-gray-700, #374151);
        }

        .mdf-btn--danger {
            background: var(--color-danger-600, #dc2626);
            color: #fff;
        }

        .mdf-btn--danger:hover {
            background: var(--color-danger-700, #b91c1c);
        }

        .mdf-btn--primary {
            background: var(--color-primary-600, #d97706);
            color: #fff;
        }

        .mdf-btn--primary:hover {
            background: var(--color-primary-700, #b45309);
        }

        .mdf-input {
            width: 100%;
            padding: 0.4375rem 0.75rem;
            border: 1px solid var(--color-gray-300, #d1d5db);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            color: var(--color-gray-900, #111827);
            background: var(--color-white, #fff);
            outline: none;
        }

        .mdf-input:focus {
            border-color: var(--color-primary-500, #f59e0b);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }

        .dark .mdf-input {
            background: var(--color-gray-700, #374151);
            border-color: var(--color-gray-600, #4b5563);
            color: var(--color-gray-100, #f3f4f6);
        }
    </style>
    @endpush

</x-filament-panels::page>
