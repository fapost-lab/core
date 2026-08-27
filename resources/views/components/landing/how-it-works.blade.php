<section id="how-it-works" class="bg-white py-24 sm:py-32">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="text-center" data-animate>
      <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl">
        {{ __('landing.how_it_works.title') }}
      </h2>
      <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-500">
        {{ __('landing.how_it_works.subtitle') }}
      </p>
    </div>

    <div class="mt-20 grid gap-12 lg:grid-cols-3 lg:gap-8">
      @foreach (__('landing.how_it_works.steps') as $index => $step)
        <div class="relative" data-animate data-animate-delay="{{ $index + 1 }}">
          {{-- Step number --}}
          <div class="flex size-14 items-center justify-center rounded-2xl bg-indigo-600 text-2xl font-black text-white">
            {{ $index + 1 }}
          </div>

          {{-- Connection line (not on last item) --}}
          @if (! $loop->last)
            <div class="absolute top-7 left-14 hidden h-0.5 w-[calc(100%-3.5rem)] bg-gradient-to-r from-indigo-200 to-transparent lg:block"></div>
          @endif

          <h3 class="mt-6 text-xl font-bold text-gray-900">{{ $step['title'] }}</h3>
          <p class="mt-3 text-base leading-relaxed text-gray-500">{{ $step['description'] }}</p>
        </div>
      @endforeach
    </div>

    {{-- App mockup: Dialog constructor --}}
    <div class="mt-20 rounded-2xl border border-gray-200 bg-gray-50 p-2 sm:p-3" data-animate>
      <div class="rounded-xl bg-white shadow-sm overflow-hidden">
        {{-- Window chrome --}}
        <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-3">
          <div class="flex gap-1.5">
            <div class="size-3 rounded-full bg-gray-200"></div>
            <div class="size-3 rounded-full bg-gray-200"></div>
            <div class="size-3 rounded-full bg-gray-200"></div>
          </div>
          <div class="ml-3 flex items-center gap-3 text-xs text-gray-400">
            <span class="rounded bg-indigo-50 px-2 py-0.5 font-medium text-indigo-600">Flows</span>
            <span>Contacts</span>
            <span>Analytics</span>
            <span>Broadcasting</span>
          </div>
        </div>

        {{-- App interface mockup --}}
        <svg viewBox="0 0 960 400" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full">
          {{-- Left sidebar --}}
          <rect x="0" y="0" width="200" height="400" fill="#FAFAFA"/>
          <rect x="200" y="0" width="1" height="400" fill="#E5E7EB"/>

          {{-- Sidebar items --}}
          <rect x="16" y="16" width="168" height="36" rx="8" fill="#EEF2FF"/>
          <rect x="32" y="28" width="12" height="12" rx="3" fill="#6366F1" opacity="0.3"/>
          <rect x="52" y="30" width="80" height="8" rx="3" fill="#6366F1" opacity="0.6"/>

          <rect x="32" y="68" width="12" height="12" rx="3" fill="#D1D5DB"/>
          <rect x="52" y="70" width="100" height="8" rx="3" fill="#D1D5DB"/>

          <rect x="32" y="96" width="12" height="12" rx="3" fill="#D1D5DB"/>
          <rect x="52" y="98" width="72" height="8" rx="3" fill="#D1D5DB"/>

          <rect x="32" y="124" width="12" height="12" rx="3" fill="#D1D5DB"/>
          <rect x="52" y="126" width="88" height="8" rx="3" fill="#D1D5DB"/>

          {{-- Section label --}}
          <rect x="16" y="164" width="60" height="6" rx="3" fill="#9CA3AF"/>
          <rect x="32" y="188" width="12" height="12" rx="3" fill="#D1D5DB"/>
          <rect x="52" y="190" width="64" height="8" rx="3" fill="#D1D5DB"/>
          <rect x="32" y="216" width="12" height="12" rx="3" fill="#D1D5DB"/>
          <rect x="52" y="218" width="96" height="8" rx="3" fill="#D1D5DB"/>

          {{-- Canvas area - flow nodes --}}
          {{-- Grid background --}}
          @for ($gy = 0; $gy < 20; $gy++)
            @for ($gx = 0; $gx < 38; $gx++)
              <circle cx="{{ 220 + $gx * 20 }}" cy="{{ 10 + $gy * 20 }}" r="0.6" fill="#E5E7EB" opacity="0.6"/>
            @endfor
          @endfor

          {{-- Connections --}}
          <path d="M440 100 C500 100, 500 80, 560 80" stroke="#6366F1" stroke-width="2" fill="none"/>
          <path d="M440 100 C500 100, 500 180, 560 180" stroke="#6366F1" stroke-width="2" fill="none"/>
          <path d="M440 100 C500 100, 500 280, 560 280" stroke="#6366F1" stroke-width="2" fill="none"/>
          <path d="M720 80 C770 80, 770 160, 810 160" stroke="#A855F7" stroke-width="2" fill="none"/>
          <path d="M720 180 C770 180, 770 160, 810 160" stroke="#A855F7" stroke-width="2" fill="none"/>
          <path d="M720 280 C770 280, 770 320, 810 320" stroke="#F97316" stroke-width="2" fill="none"/>

          {{-- Trigger node --}}
          <rect x="280" y="76" width="160" height="48" rx="12" fill="#6366F1"/>
          <circle cx="300" cy="100" r="10" fill="white" opacity="0.2"/>
          <path d="M296 100L305 100M300 96L300 104" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
          <rect x="316" y="92" width="80" height="7" rx="3" fill="white" opacity="0.9"/>
          <rect x="316" y="103" width="56" height="5" rx="2.5" fill="white" opacity="0.4"/>

          {{-- Message node --}}
          <rect x="560" y="56" width="160" height="48" rx="12" fill="white" stroke="#E5E7EB" stroke-width="1.5"/>
          <rect x="580" y="68" width="10" height="10" rx="2" fill="#6366F1" opacity="0.15"/>
          <rect x="580" y="70" width="10" height="10" rx="5" fill="#6366F1" opacity="0.3"/>
          <rect x="596" y="72" width="70" height="6" rx="3" fill="#374151" opacity="0.7"/>
          <rect x="580" y="87" width="120" height="5" rx="2.5" fill="#9CA3AF" opacity="0.5"/>

          {{-- Condition node --}}
          <rect x="560" y="156" width="160" height="48" rx="12" fill="white" stroke="#F97316" stroke-width="1.5"/>
          <rect x="580" y="172" width="10" height="10" rx="5" fill="#F97316" opacity="0.3"/>
          <rect x="596" y="172" width="60" height="6" rx="3" fill="#374151" opacity="0.7"/>
          <rect x="580" y="187" width="100" height="5" rx="2.5" fill="#9CA3AF" opacity="0.5"/>

          {{-- Wait node --}}
          <rect x="560" y="256" width="160" height="48" rx="12" fill="white" stroke="#E5E7EB" stroke-width="1.5"/>
          <rect x="580" y="272" width="10" height="10" rx="5" fill="#10B981" opacity="0.3"/>
          <rect x="596" y="272" width="48" height="6" rx="3" fill="#374151" opacity="0.7"/>
          <rect x="580" y="287" width="80" height="5" rx="2.5" fill="#9CA3AF" opacity="0.5"/>

          {{-- AI node --}}
          <rect x="810" y="136" width="120" height="48" rx="12" fill="#7C3AED" opacity="0.1" stroke="#7C3AED"
                stroke-width="1.5"/>
          <text x="842" y="165" font-size="11" font-weight="700" fill="#7C3AED" opacity="0.9">AI Response</text>
          <rect x="830" y="172" width="80" height="4" rx="2" fill="#7C3AED" opacity="0.2"/>

          {{-- Action node --}}
          <rect x="810" y="296" width="120" height="48" rx="12" fill="white" stroke="#E5E7EB" stroke-width="1.5"/>
          <rect x="830" y="312" width="10" height="10" rx="2" fill="#F97316" opacity="0.3"/>
          <rect x="846" y="312" width="55" height="6" rx="3" fill="#374151" opacity="0.7"/>
          <rect x="830" y="327" width="80" height="4" rx="2" fill="#9CA3AF" opacity="0.4"/>

          {{-- Right panel --}}
          <rect x="959" y="0" width="1" height="400" fill="#E5E7EB"/>
        </svg>
      </div>
    </div>
  </div>
</section>
