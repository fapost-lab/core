@props(['file', 'signedUrl'])

<div style="display:flex;flex-direction:column;align-items:center;gap:1rem;padding:3rem 1rem;">
    <svg style="width:3.5rem;height:3.5rem;color:#9ca3af" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
    </svg>
    <div style="text-align:center;">
        <p style="font-weight:500;color:#111827;margin:0 0 0.25rem;">{{ $file->name }}</p>
        <p style="font-size:0.875rem;color:#6b7280;margin:0;">{{ $file->blob?->mime_type ?? __('Unknown type') }}</p>
    </div>
</div>
