{{-- Shown on every panel page while the tenant's access mode carries a notice. Plain styles: panel themes compile only the classes they scan. --}}
<div role="status" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.75rem;padding:0.5rem 1rem;background:{{ $stopped ? '#b91c1c' : '#1d4ed8' }};color:#fff;font-size:0.875rem;">
    <span><strong>{{ $title }}</strong>@if ($message) {{ $message }}@endif</span>
    @if ($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}" style="border:1px solid #fff;border-radius:0.25rem;color:#fff;padding:0.125rem 0.625rem;text-decoration:none;">
            {{ $actionLabel }}
        </a>
    @endif
</div>
