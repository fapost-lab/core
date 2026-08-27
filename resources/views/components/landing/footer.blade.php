<footer class="bg-[var(--color-surface-darker)] py-16">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="grid gap-12 sm:grid-cols-2 lg:grid-cols-4">
      {{-- Brand --}}
      <div class="lg:col-span-2">
        <a href="/"
           class="font-[family-name:var(--font-heading)] text-2xl font-black text-white">{{ __('landing.nav.logo') }}</a>
        <p class="mt-3 max-w-sm text-sm leading-relaxed text-gray-500">
          {{ __('landing.footer.tagline') }}
        </p>

        {{-- Language Switcher --}}
        <div class="mt-6 flex items-center gap-1 text-sm">
          @foreach (['ru' => 'RU', 'en' => 'EN', 'uk' => 'UK'] as $code => $label)
            @if (app()->getLocale() === $code)
              <span class="px-2 py-1 font-bold text-indigo-400">{{ $label }}</span>
            @else
              <a href="?lang={{ $code }}"
                 class="px-2 py-1 text-gray-500 hover:text-white transition-colors">{{ $label }}</a>
            @endif
            @if (! $loop->last)
              <span class="text-gray-700">·</span>
            @endif
          @endforeach
        </div>
      </div>

      {{-- Product links --}}
      <div>
        <p class="text-xs font-bold tracking-wider text-gray-400 uppercase">{{ __('landing.footer.product') }}</p>
        <nav class="mt-4 flex flex-col gap-3">
          <a href="#features"
             class="text-sm text-gray-500 hover:text-white transition-colors">{{ __('landing.footer.links.features') }}</a>
          <a href="#cases"
             class="text-sm text-gray-500 hover:text-white transition-colors">{{ __('landing.footer.links.solutions') }}</a>
          <a href="#deployment"
             class="text-sm text-gray-500 hover:text-white transition-colors">{{ __('landing.footer.links.deployment') }}</a>
        </nav>
      </div>

      {{-- Company links --}}
      <div>
        <p class="text-xs font-bold tracking-wider text-gray-400 uppercase">{{ __('landing.footer.company') }}</p>
        <nav class="mt-4 flex flex-col gap-3">
          <a href="#presale-form"
             class="text-sm text-gray-500 hover:text-white transition-colors">{{ __('landing.footer.links.contact') }}</a>
        </nav>
      </div>
    </div>

    <div class="mt-12 border-t border-white/5 pt-8">
      <p class="text-sm text-gray-600">{{ __('landing.footer.copyright', ['year' => date('Y')]) }}</p>
    </div>
  </div>
</footer>
