<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.75rem;">

        {{-- Breadcrumb trail --}}
        <nav style="display:flex;flex-wrap:wrap;align-items:center;gap:0.375rem;font-size:0.875rem;color:#6b7280;">
            <button
                type="button"
                wire:click="navigateToFolder(null)"
                style="display:inline-flex;align-items:center;gap:0.375rem;background:none;border:none;cursor:pointer;padding:0;color:{{ $this->currentFolderId === null ? '#111827' : '#6b7280' }};font-weight:{{ $this->currentFolderId === null ? '600' : '400' }};"
            >
                <svg style="width:1rem;height:1rem;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                </svg>
                {{ __('media.navigation.root') }}
            </button>

            @foreach ($folderBreadcrumbs as $crumb)
                <span style="color:#d1d5db;user-select:none;">/</span>
                @if (! $loop->last)
                    <button
                        type="button"
                        wire:click="navigateToFolder('{{ $crumb['id'] }}')"
                        style="background:none;border:none;cursor:pointer;padding:0;color:#6b7280;"
                    >{{ $crumb['name'] }}</button>
                @else
                    <span style="font-weight:600;color:#111827;">{{ $crumb['name'] }}</span>
                @endif
            @endforeach
        </nav>

        {{-- Subfolders row (back arrow + chips) --}}
        @if ($this->currentFolderId !== null || count($subfolders) > 0)
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:0.5rem;">

                @if ($this->currentFolderId !== null)
                    @php
                        $parentId = count($folderBreadcrumbs) >= 2
                            ? $folderBreadcrumbs[count($folderBreadcrumbs) - 2]['id']
                            : null;
                    @endphp
                    <button
                        type="button"
                        wire:click="navigateToFolder({{ $parentId === null ? 'null' : "'" . $parentId . "'" }})"
                        title="{{ __('media.navigation.back') }}"
                        style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:50%;border:1px solid #e5e7eb;background:#fff;cursor:pointer;color:#6b7280;flex-shrink:0;"
                        onmouseover="this.style.borderColor='#9ca3af';this.style.color='#111827'"
                        onmouseout="this.style.borderColor='#e5e7eb';this.style.color='#6b7280'"
                    >
                        <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </button>
                @endif

                @foreach ($subfolders as $folder)
                    <button
                        type="button"
                        wire:click="navigateToFolder('{{ $folder['id'] }}')"
                        style="display:inline-flex;align-items:center;gap:0.375rem;padding:0.375rem 0.75rem;border-radius:0.5rem;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:0.8125rem;color:#374151;white-space:nowrap;max-width:180px;"
                        onmouseover="this.style.borderColor='#fbbf24'"
                        onmouseout="this.style.borderColor='#e5e7eb'"
                    >
                        <svg style="width:1rem;height:1rem;flex-shrink:0;color:#fbbf24" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z"/>
                        </svg>
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $folder['name'] }}</span>
                    </button>
                @endforeach

            </div>
        @endif

    </div>

    {{-- Files table --}}
    {{ $this->content }}
</x-filament-panels::page>
