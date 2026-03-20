@props(['hcaptchaSiteKey'])

<section id="presale-form" class="bg-gray-50 py-24 sm:py-32">
  <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
    <div class="text-center" data-animate>
      <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">
        {{ __('landing.form.title') }}
      </h2>
      <p class="mt-4 text-lg text-gray-500">
        {{ __('landing.form.subtitle') }}
      </p>
    </div>

    <div class="mt-12 rounded-2xl border border-gray-200 bg-white p-8 shadow-sm sm:p-10" data-animate
         data-animate-delay="1">
      {{-- Form --}}
      <form id="presale-form-el" class="space-y-5" novalidate>
        {{-- Name --}}
        <div>
          <label for="name" class="block text-sm font-semibold text-gray-700">{{ __('landing.form.name') }}</label>
          <input type="text" id="name" name="name" required
                 class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
          <p class="mt-1 hidden text-sm text-red-600" data-error="name"></p>
        </div>

        {{-- Company --}}
        <div>
          <label for="company"
                 class="block text-sm font-semibold text-gray-700">{{ __('landing.form.company') }}</label>
          <input type="text" id="company" name="company" required
                 class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
          <p class="mt-1 hidden text-sm text-red-600" data-error="company"></p>
        </div>

        {{-- Email --}}
        <div>
          <label for="email" class="block text-sm font-semibold text-gray-700">{{ __('landing.form.email') }}</label>
          <input type="email" id="email" name="email" required
                 class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
          <p class="mt-1 hidden text-sm text-red-600" data-error="email"></p>
        </div>

        {{-- Contact Method --}}
        <div>
          <label for="messenger_preference"
                 class="block text-sm font-semibold text-gray-700">{{ __('landing.form.messenger') }}</label>
          <select id="messenger_preference" name="messenger_preference" required
                  class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
            <option value="">—</option>
            @foreach (__('landing.form.messenger_options') as $value => $label)
              <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
          </select>
          <p class="mt-1 hidden text-sm text-red-600" data-error="messenger_preference"></p>
        </div>

        {{-- Message --}}
        <div>
          <label for="message"
                 class="block text-sm font-semibold text-gray-700">{{ __('landing.form.message') }}</label>
          <textarea id="message" name="message" rows="4"
                    placeholder="{{ __('landing.form.message_placeholder') }}"
                    class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition"></textarea>
          <p class="mt-1 hidden text-sm text-red-600" data-error="message"></p>
        </div>

        {{-- Invisible hCaptcha container --}}
        <div id="hcaptcha-container"></div>
        <p class="mt-1 hidden text-sm text-red-600" data-error="h-captcha-response"></p>

        {{-- Submit --}}
        <button type="submit" id="presale-submit"
                class="w-full rounded-xl bg-indigo-600 px-6 py-3.5 text-base font-bold text-white shadow-sm hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500/50 focus:outline-none transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
          {{ __('landing.form.submit') }}
        </button>

        <p class="hidden text-center text-sm text-red-600" id="presale-error">{{ __('landing.form.error') }}</p>
      </form>

      {{-- Success --}}
      <div id="presale-success" class="hidden py-12 text-center">
        <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-green-50">
          <svg class="size-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
          </svg>
        </div>
        <p class="mt-4 text-lg font-semibold text-gray-900">{{ __('landing.form.success') }}</p>
      </div>
    </div>
  </div>
</section>

<script>
    const HCAPTCHA_SITE_KEY = '{{ $hcaptchaSiteKey }}';
    let hcaptchaWidgetId = null;
    let captchaResolve = null;
    let captchaReject = null;

    // Called by hcaptcha when challenge is passed
    function onCaptchaPass(token) {
        if (captchaResolve) {
            captchaResolve(token);
            captchaResolve = null;
            captchaReject = null;
        }
    }

    // Called by hcaptcha on error
    function onCaptchaError() {
        if (captchaReject) {
            captchaReject(new Error('Captcha failed'));
            captchaResolve = null;
            captchaReject = null;
        }
    }

    // Called by hcaptcha when widget is closed without completing
    function onCaptchaClose() {
        if (captchaReject) {
            captchaReject(new Error('Captcha closed'));
            captchaResolve = null;
            captchaReject = null;
        }
    }

    // Called when hcaptcha API is fully loaded (via onload param)
    function onHcaptchaLoad() {
        hcaptchaWidgetId = hcaptcha.render('hcaptcha-container', {
            sitekey: HCAPTCHA_SITE_KEY,
            size: 'invisible',
            callback: 'onCaptchaPass',
            'error-callback': 'onCaptchaError',
            'close-callback': 'onCaptchaClose',
        });
    }

    function getCaptchaToken() {
        return new Promise((resolve, reject) => {
            if (typeof hcaptcha === 'undefined' || hcaptchaWidgetId === null) {
                reject(new Error('hCaptcha not loaded'));
                return;
            }
            captchaResolve = resolve;
            captchaReject = reject;
            hcaptcha.reset(hcaptchaWidgetId);
            hcaptcha.execute(hcaptchaWidgetId);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('presale-form-el');
        const submitBtn = document.getElementById('presale-submit');
        const errorEl = document.getElementById('presale-error');
        const successEl = document.getElementById('presale-success');

        function clearErrors() {
            form.querySelectorAll('[data-error]').forEach(el => {
                el.textContent = '';
                el.classList.add('hidden');
            });
            errorEl.classList.add('hidden');
        }

        function showFieldErrors(errors) {
            Object.entries(errors).forEach(([field, messages]) => {
                const el = form.querySelector(`[data-error="${field}"]`);
                if (el) {
                    el.textContent = Array.isArray(messages) ? messages[0] : messages;
                    el.classList.remove('hidden');
                }
            });
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            clearErrors();
            submitBtn.disabled = true;

            try {
                // Get invisible captcha token
                const captchaToken = await getCaptchaToken();

                const formData = new FormData(form);
                const data = Object.fromEntries(formData.entries());
                data['h-captcha-response'] = captchaToken;

                const response = await fetch('{{ route("presale.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(data),
                });

                if (response.ok) {
                    form.classList.add('hidden');
                    successEl.classList.remove('hidden');
                    return;
                }

                if (response.status === 422) {
                    const result = await response.json();
                    showFieldErrors(result.errors || {});
                } else {
                    errorEl.classList.remove('hidden');
                }
            } catch (err) {
                console.error('Form submit error:', err);
                errorEl.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
            }
        });
    });
</script>
