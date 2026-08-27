<section
        class="relative overflow-hidden bg-[var(--color-surface-dark)] pt-32 pb-20 sm:pt-40 sm:pb-28 lg:pt-48 lg:pb-36">
  {{-- Background gradient --}}
  <div class="absolute inset-0">
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[1200px] h-[800px] bg-gradient-to-b from-indigo-600/20 via-purple-600/10 to-transparent rounded-full blur-3xl"></div>
    <div class="absolute bottom-0 right-0 w-[600px] h-[400px] bg-gradient-to-tl from-orange-500/10 to-transparent rounded-full blur-3xl"></div>
  </div>

  <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="grid items-center gap-16 lg:grid-cols-2 lg:gap-20">
      {{-- Text --}}
      <div>
        {{-- Badge --}}
        <div class="mb-8" data-animate>
                    <span class="inline-flex items-center gap-2 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-4 py-1.5 text-sm font-medium text-indigo-300">
                        <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path
                                  d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/></svg>
                        {{ __('landing.hero.badge') }}
                    </span>
        </div>

        {{-- Headlines --}}
        <h1 data-animate data-animate-delay="1">
                    <span class="block text-4xl font-black tracking-tight text-white sm:text-6xl lg:text-5xl xl:text-6xl 2xl:text-7xl">
                        {{ __('landing.hero.headline_1') }}
                    </span>
          <span class="block text-4xl font-black tracking-tight text-gradient sm:text-6xl lg:text-5xl xl:text-6xl 2xl:text-7xl">
                        {{ __('landing.hero.headline_2') }}
                    </span>
          <span class="block text-4xl font-black tracking-tight text-white sm:text-6xl lg:text-5xl xl:text-6xl 2xl:text-7xl">
                        {{ __('landing.hero.headline_3') }}
                    </span>
        </h1>

        <p class="mt-8 max-w-xl text-lg leading-relaxed text-gray-400 sm:text-xl" data-animate data-animate-delay="2">
          {{ __('landing.hero.subheadline') }}
        </p>

        {{-- CTAs --}}
        <div class="mt-10 flex flex-col gap-4 sm:flex-row" data-animate data-animate-delay="3">
          <a href="#presale-form"
             class="inline-flex items-center justify-center rounded-full bg-white px-8 py-4 text-base font-bold text-gray-900 shadow-lg hover:bg-orange-400 hover:text-white transition-all duration-200">
            {{ __('landing.hero.cta_primary') }}
            <svg class="ml-2 size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
            </svg>
          </a>
          <a href="#how-it-works"
             class="inline-flex items-center justify-center rounded-full border border-white/20 px-8 py-4 text-base font-semibold text-white hover:bg-white/10 transition-all duration-200">
            {{ __('landing.hero.cta_secondary') }}
          </a>
        </div>
      </div>

      {{-- App Mockup: Flow Builder Interface --}}
      <div class="relative" data-animate data-animate-delay="2">
        <div class="glow-ring rounded-2xl border border-white/10 bg-white/5 p-2 backdrop-blur-sm">
          <div class="rounded-xl bg-[#1a1730] overflow-hidden">
            {{-- Window chrome --}}
            <div class="flex items-center gap-2 border-b border-white/5 px-4 py-3">
              <div class="flex gap-1.5">
                <div class="size-3 rounded-full bg-red-500/60"></div>
                <div class="size-3 rounded-full bg-yellow-500/60"></div>
                <div class="size-3 rounded-full bg-green-500/60"></div>
              </div>
              <div class="ml-4 rounded-md bg-white/5 px-3 py-1 text-xs text-white/40">Flow Builder — Welcome Scenario
              </div>
            </div>

            {{-- Flow Builder Canvas --}}
            <svg viewBox="0 0 560 360" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full">
              {{-- Grid dots --}}
              @for ($y = 0; $y < 18; $y++)
                @for ($x = 0; $x < 28; $x++)
                  <circle cx="{{ 10 + $x * 20 }}" cy="{{ 10 + $y * 20 }}" r="0.8" fill="white" opacity="0.06"/>
                @endfor
              @endfor

              {{-- Connection lines --}}
              <path d="M185 70 C220 70, 220 140, 255 140" stroke="#6366f1" stroke-width="2" opacity="0.6"/>
              <path d="M185 70 C220 70, 220 230, 255 230" stroke="#6366f1" stroke-width="2" opacity="0.6"/>
              <path d="M415 140 C450 140, 450 185, 480 185" stroke="#a855f7" stroke-width="2" opacity="0.6"/>
              <path d="M415 230 C450 230, 450 185, 480 185" stroke="#a855f7" stroke-width="2" opacity="0.6"/>

              {{-- Start Node --}}
              <g>
                <rect x="40" y="48" width="145" height="44" rx="10" fill="#6366f1"/>
                <circle cx="58" cy="70" r="8" fill="white" opacity="0.2"/>
                <path d="M55 70 L62 70 M58.5 67 L58.5 73" stroke="white" stroke-width="1.5" stroke-linecap="round"
                      opacity="0.8"/>
                <rect x="72" y="63" width="70" height="5" rx="2.5" fill="white" opacity="0.8"/>
                <rect x="72" y="73" width="48" height="4" rx="2" fill="white" opacity="0.4"/>
              </g>

              {{-- Message Node --}}
              <g>
                <rect x="255" y="116" width="160" height="48" rx="10" fill="#1e1b4b" stroke="#6366f1"
                      stroke-width="1.5"/>
                <rect x="275" y="126" width="10" height="10" rx="2" fill="#6366f1" opacity="0.5"/>
                <rect x="291" y="128" width="65" height="5" rx="2.5" fill="white" opacity="0.7"/>
                <rect x="275" y="143" width="120" height="4" rx="2" fill="white" opacity="0.25"/>
                <rect x="275" y="151" width="90" height="4" rx="2" fill="white" opacity="0.15"/>
              </g>

              {{-- Condition Node --}}
              <g>
                <rect x="255" y="206" width="160" height="48" rx="10" fill="#1e1b4b" stroke="#f97316"
                      stroke-width="1.5"/>
                <rect x="275" y="216" width="10" height="10" rx="5" fill="#f97316" opacity="0.5"/>
                <rect x="291" y="218" width="55" height="5" rx="2.5" fill="white" opacity="0.7"/>
                <rect x="275" y="233" width="110" height="4" rx="2" fill="white" opacity="0.25"/>
                <rect x="275" y="241" width="80" height="4" rx="2" fill="white" opacity="0.15"/>
              </g>

              {{-- AI Node --}}
              <g>
                <rect x="480" y="161" width="60" height="48" rx="10" fill="#7c3aed" opacity="0.9"/>
                <text x="510" y="182" text-anchor="middle" fill="white" font-size="10" font-weight="600" opacity="0.9">
                  AI
                </text>
                <rect x="492" y="192" width="36" height="4" rx="2" fill="white" opacity="0.4"/>
              </g>

              {{-- Sidebar panel --}}
              <rect x="0" y="0" width="32" height="360" fill="white" opacity="0.03"/>
              <rect x="8" y="16" width="16" height="16" rx="4" fill="#6366f1" opacity="0.3"/>
              <rect x="8" y="40" width="16" height="16" rx="4" fill="white" opacity="0.06"/>
              <rect x="8" y="64" width="16" height="16" rx="4" fill="white" opacity="0.06"/>
              <rect x="8" y="88" width="16" height="16" rx="4" fill="white" opacity="0.06"/>
              <rect x="8" y="112" width="16" height="16" rx="4" fill="white" opacity="0.06"/>

              {{-- Active node selection indicator --}}
              <rect x="251" y="112" width="168" height="56" rx="12" stroke="#6366f1" stroke-width="1"
                    stroke-dasharray="4 3" opacity="0.4"/>

              {{-- Floating label --}}
              <g>
                <rect x="330" y="82" width="72" height="22" rx="6" fill="#10b981" opacity="0.15"/>
                <rect x="330" y="82" width="72" height="22" rx="6" stroke="#10b981" stroke-width="1" opacity="0.3"/>
                <circle cx="342" cy="93" r="3" fill="#10b981" opacity="0.8"/>
                <rect x="349" y="90" width="42" height="5" rx="2.5" fill="#10b981" opacity="0.6"/>
              </g>

              {{-- Bottom status bar --}}
              <rect x="0" y="340" width="560" height="20" fill="white" opacity="0.02"/>
              <rect x="40" y="347" width="50" height="4" rx="2" fill="white" opacity="0.1"/>
              <rect x="100" y="347" width="30" height="4" rx="2" fill="#10b981" opacity="0.2"/>
              <rect x="480" y="347" width="60" height="4" rx="2" fill="white" opacity="0.1"/>
            </svg>
          </div>
        </div>

        {{-- Floating chat preview --}}
        <div class="absolute -bottom-6 -left-6 rounded-xl border border-white/10 bg-[#1a1730] p-4 shadow-2xl sm:-left-10 sm:-bottom-8"
             data-animate data-animate-delay="4">
          <div class="flex items-start gap-3">
            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-indigo-500/20">
              <svg class="size-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                   stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
              </svg>
            </div>
            <div>
              <div class="rounded-lg bg-white/5 px-3 py-2">
                <p class="text-xs text-white/60">Bot response</p>
                <div class="mt-1 flex gap-1">
                  <div class="h-2 w-12 rounded bg-white/20"></div>
                  <div class="h-2 w-8 rounded bg-white/15"></div>
                  <div class="h-2 w-16 rounded bg-white/20"></div>
                </div>
              </div>
              <div class="mt-1 flex items-center gap-1.5">
                <div class="size-1.5 rounded-full bg-green-400"></div>
                <span class="text-[10px] text-green-400/70">AI-powered</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Stats bar --}}
    <div class="mt-20 grid gap-8 border-t border-white/10 pt-12 sm:grid-cols-3 lg:mt-28" data-animate
         data-animate-delay="4">
      <div>
        <p class="text-2xl font-bold text-white sm:text-3xl">{{ __('landing.stats.channels') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('landing.stats.channels_label') }}</p>
      </div>
      <div>
        <p class="text-2xl font-bold text-white sm:text-3xl">{{ __('landing.stats.automation') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('landing.stats.automation_label') }}</p>
      </div>
      <div>
        <p class="text-2xl font-bold text-white sm:text-3xl">{{ __('landing.stats.ai') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('landing.stats.ai_label') }}</p>
      </div>
    </div>
  </div>
</section>
