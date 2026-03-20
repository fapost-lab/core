<section id="deployment" class="bg-[var(--color-surface-dark)] py-24 sm:py-32">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl text-center" data-animate>
      <h2 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl lg:text-5xl">
        {{ __('landing.deployment.title') }}
      </h2>
      <p class="mt-4 text-lg text-gray-400">
        {{ __('landing.deployment.subtitle') }}
      </p>
    </div>

    <div class="mx-auto mt-16 grid max-w-5xl gap-6 lg:grid-cols-2">
      {{-- Cloud --}}
      <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/5 p-8 backdrop-blur-sm lg:p-10"
           data-animate data-animate-delay="1">
        {{-- Badge --}}
        <span class="inline-flex items-center rounded-full bg-indigo-500/10 px-3 py-1 text-xs font-semibold text-indigo-300">
                    {{ __('landing.deployment.saas.badge') }}
                </span>

        <div class="mt-4 flex items-center gap-3">
          <div class="flex size-12 items-center justify-center rounded-xl bg-indigo-500/10">
            <svg class="size-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M2.25 15a4.5 4.5 0 0 0 4.5 4.5H18a3.75 3.75 0 0 0 1.332-7.257 3 3 0 0 0-3.758-3.848 5.25 5.25 0 0 0-10.233 2.33A4.502 4.502 0 0 0 2.25 15Z"/>
            </svg>
          </div>
          <h3 class="text-2xl font-bold text-white">{{ __('landing.deployment.saas.title') }}</h3>
        </div>

        <p class="mt-3 text-gray-400">{{ __('landing.deployment.saas.description') }}</p>

        <ul class="mt-8 space-y-4">
          @foreach (__('landing.deployment.saas.features') as $feature)
            <li class="flex items-start gap-3">
              <svg class="mt-0.5 size-5 flex-shrink-0 text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                      d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                      clip-rule="evenodd"/>
              </svg>
              <span class="text-sm text-gray-300">{{ $feature }}</span>
            </li>
          @endforeach
        </ul>

        {{-- Gradient accent --}}
        <div class="absolute -top-20 -right-20 size-40 rounded-full bg-indigo-500/10 blur-3xl"></div>
      </div>

      {{-- Self-Hosted --}}
      <div class="relative overflow-hidden rounded-2xl border border-orange-500/20 bg-white/5 p-8 backdrop-blur-sm lg:p-10"
           data-animate data-animate-delay="2">
                <span class="inline-flex items-center rounded-full bg-orange-500/10 px-3 py-1 text-xs font-semibold text-orange-300">
                    {{ __('landing.deployment.selfhosted.badge') }}
                </span>

        <div class="mt-4 flex items-center gap-3">
          <div class="flex size-12 items-center justify-center rounded-xl bg-orange-500/10">
            <svg class="size-6 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M21.75 17.25v-.228a4.5 4.5 0 0 0-.12-1.03l-2.268-9.64a3.375 3.375 0 0 0-3.285-2.602H7.923a3.375 3.375 0 0 0-3.285 2.602l-2.268 9.64a4.5 4.5 0 0 0-.12 1.03v.228m19.5 0a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3m19.5 0a3 3 0 0 0-3-3H5.25a3 3 0 0 0-3 3m16.5 0h.008v.008h-.008v-.008Zm-3 0h.008v.008h-.008v-.008Z"/>
            </svg>
          </div>
          <h3 class="text-2xl font-bold text-white">{{ __('landing.deployment.selfhosted.title') }}</h3>
        </div>

        <p class="mt-3 text-gray-400">{{ __('landing.deployment.selfhosted.description') }}</p>

        <ul class="mt-8 space-y-4">
          @foreach (__('landing.deployment.selfhosted.features') as $feature)
            <li class="flex items-start gap-3">
              <svg class="mt-0.5 size-5 flex-shrink-0 text-orange-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                      d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                      clip-rule="evenodd"/>
              </svg>
              <span class="text-sm text-gray-300">{{ $feature }}</span>
            </li>
          @endforeach
        </ul>

        <div class="absolute -bottom-20 -left-20 size-40 rounded-full bg-orange-500/10 blur-3xl"></div>
      </div>
    </div>
  </div>
</section>
