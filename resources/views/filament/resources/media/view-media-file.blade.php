<x-filament-panels::page>
    <div class="space-y-4">

        {{-- Back button --}}
        <div style="padding-bottom:0.75rem;border-bottom:1px solid #f3f4f6;">
            <button
                type="button"
                onclick="history.back()"
                style="display:inline-flex;align-items:center;gap:0.375rem;background:none;border:none;cursor:pointer;padding:0;font-size:0.875rem;color:#6b7280;"
                onmouseover="this.style.color='#111827'"
                onmouseout="this.style.color='#6b7280'"
            >
                <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
                {{ __('media.navigation.back') }}
            </button>
        </div>

        {{-- Preview --}}
        <div class="overflow-hidden rounded-xl bg-white shadow dark:bg-gray-900">
            <div class="p-6">
                @include($previewView, ['file' => $file, 'signedUrl' => $signedUrl])
            </div>
        </div>

        {{-- Metadata card --}}
        <div class="overflow-hidden rounded-xl bg-white shadow dark:bg-gray-900">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));border-bottom:1px solid #f3f4f6;">
                <div style="padding:1rem 1.25rem;border-right:1px solid #f3f4f6;">
                    <div style="font-size:0.6875rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:0.25rem;">
                        {{ __('media.fields.kind') }}
                    </div>
                    <div style="font-size:0.9375rem;font-weight:500;color:#111827;">
                        {{ __('media.kinds.' . $kind) }}
                    </div>
                </div>
                <div style="padding:1rem 1.25rem;border-right:1px solid #f3f4f6;">
                    <div style="font-size:0.6875rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:0.25rem;">
                        {{ __('media.fields.mime_type') }}
                    </div>
                    <div style="font-size:0.8125rem;font-family:monospace;color:#374151;">
                        {{ $mimeType ?? '—' }}
                    </div>
                </div>
                <div style="padding:1rem 1.25rem;">
                    <div style="font-size:0.6875rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:0.25rem;">
                        {{ __('media.fields.size') }}
                    </div>
                    <div style="font-size:0.9375rem;font-weight:500;color:#111827;">
                        @if ($sizeBytes >= 1024 * 1024)
                            {{ number_format($sizeBytes / 1024 / 1024, 1) }} MB
                        @elseif ($sizeBytes >= 1024)
                            {{ number_format($sizeBytes / 1024, 1) }} KB
                        @else
                            {{ $sizeBytes }} B
                        @endif
                    </div>
                </div>
            </div>

            @if ($signedUrl)
                <div style="padding:1rem 1.25rem;">
                    <a
                        href="{{ $signedUrl }}"
                        download="{{ $file->name }}"
                        style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1.25rem;border-radius:0.5rem;background:#f59e0b;color:#fff;font-size:0.875rem;font-weight:500;text-decoration:none;"
                        onmouseover="this.style.background='#d97706'"
                        onmouseout="this.style.background='#f59e0b'"
                    >
                        <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        {{ __('media.actions.download') }}
                    </a>
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
