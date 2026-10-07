{{-- Shown on every panel page while the session is a support session. Plain styles: panel themes compile only the classes they scan. --}}
<div role="status" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.75rem;padding:0.5rem 1rem;background:#b45309;color:#fff;font-size:0.875rem;">
    <span>{{ __('staff.support_access.banner', ['name' => $name, 'email' => $email]) }}</span>
    <form method="POST" action="{{ route('support.leave') }}" style="margin:0;">
        @csrf
        <button type="submit" style="background:transparent;border:1px solid #fff;border-radius:0.25rem;color:#fff;cursor:pointer;padding:0.125rem 0.625rem;">
            {{ __('staff.support_access.leave') }}
        </button>
    </form>
</div>
