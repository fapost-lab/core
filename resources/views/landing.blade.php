<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title>{{ __('landing.meta.title') }}</title>
  <meta name="description" content="{{ __('landing.meta.description') }}">
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=pt-sans:400,400i,700,700i|montserrat:600,700,800,900&display=swap"
        rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <script src="https://js.hcaptcha.com/1/api.js?onload=onHcaptchaLoad&render=explicit" async defer></script>
</head>
<body class="antialiased bg-white text-gray-900">

{{-- Header --}}
<header id="site-header" class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" data-scrolled="false">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-20 items-center justify-between">
      {{-- Logo --}}
      <a href="/"
         class="font-[family-name:var(--font-heading)] text-2xl font-black tracking-tight text-white transition-colors"
         id="header-logo">
        {{ __('landing.nav.logo') }}
      </a>

      {{-- Desktop Nav --}}
      <nav class="hidden items-center gap-8 lg:flex" id="header-nav">
        <a href="#how-it-works"
           class="text-sm font-medium text-white/70 hover:text-white transition-colors">{{ __('landing.nav.how_it_works') }}</a>
        <a href="#features"
           class="text-sm font-medium text-white/70 hover:text-white transition-colors">{{ __('landing.nav.features') }}</a>
        <a href="#cases"
           class="text-sm font-medium text-white/70 hover:text-white transition-colors">{{ __('landing.nav.cases') }}</a>
        <a href="#deployment"
           class="text-sm font-medium text-white/70 hover:text-white transition-colors">{{ __('landing.nav.deployment') }}</a>
      </nav>

      <div class="flex items-center gap-5">
        {{-- Language Switcher --}}
        <div class="hidden items-center gap-1 text-sm sm:flex" id="header-lang">
          @foreach (['ru' => 'RU', 'en' => 'EN', 'uk' => 'UK'] as $code => $label)
            @if (app()->getLocale() === $code)
              <span class="px-1.5 py-0.5 font-bold text-white">{{ $label }}</span>
            @else
              <a href="?lang={{ $code }}"
                 class="px-1.5 py-0.5 text-white/50 hover:text-white transition-colors">{{ $label }}</a>
            @endif
            @if (! $loop->last)
              <span class="text-white/20">·</span>
            @endif
          @endforeach
        </div>

        {{-- CTA --}}
        <a href="#presale-form"
           class="rounded-full bg-white px-5 py-2.5 text-sm font-bold text-[var(--color-surface-dark)] hover:bg-orange-400 hover:text-white transition-all duration-200"
           id="header-cta">
          {{ __('landing.nav.cta') }}
        </a>
      </div>
    </div>
  </div>
</header>

<main>
  <x-landing.hero/>
  <x-landing.how-it-works/>
  <x-landing.features/>
  <x-landing.cases/>
  <x-landing.deployment/>
  <x-landing.presale-form :hcaptchaSiteKey="$hcaptchaSiteKey"/>
</main>

<x-landing.footer/>

<script>
    // Header style on scroll — transparent over hero, white on scroll
    const header = document.getElementById('site-header');
    const logo = document.getElementById('header-logo');
    const nav = document.getElementById('header-nav');
    const lang = document.getElementById('header-lang');
    const cta = document.getElementById('header-cta');

    function updateHeader() {
        const scrolled = window.scrollY > 80;
        header.dataset.scrolled = scrolled;

        if (scrolled) {
            header.classList.add('bg-white', 'shadow-lg', 'shadow-black/5');
            header.classList.remove('bg-transparent');
            logo.classList.replace('text-white', 'text-gray-900');
            if (nav) nav.querySelectorAll('a').forEach(a => {
                a.classList.replace('text-white/70', 'text-gray-500');
                a.classList.replace('hover:text-white', 'hover:text-indigo-600');
            });
            if (lang) {
                lang.querySelectorAll('span').forEach(s => s.classList.replace('text-white', 'text-indigo-600'));
                lang.querySelectorAll('a').forEach(a => {
                    a.classList.replace('text-white/50', 'text-gray-400');
                    a.classList.replace('hover:text-white', 'hover:text-indigo-600');
                });
                lang.querySelectorAll('.text-white\\/20').forEach(d => {
                    d.classList.remove('text-white/20');
                    d.classList.add('text-gray-300');
                });
            }
            cta.classList.remove('bg-white', 'text-[var(--color-surface-dark)]', 'hover:bg-orange-400', 'hover:text-white');
            cta.classList.add('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
        } else {
            header.classList.remove('bg-white', 'shadow-lg', 'shadow-black/5');
            header.classList.add('bg-transparent');
            logo.classList.replace('text-gray-900', 'text-white');
            if (nav) nav.querySelectorAll('a').forEach(a => {
                a.classList.replace('text-gray-500', 'text-white/70');
                a.classList.replace('hover:text-indigo-600', 'hover:text-white');
            });
            if (lang) {
                lang.querySelectorAll('span').forEach(s => s.classList.replace('text-indigo-600', 'text-white'));
                lang.querySelectorAll('a').forEach(a => {
                    a.classList.replace('text-gray-400', 'text-white/50');
                    a.classList.replace('hover:text-indigo-600', 'hover:text-white');
                });
                lang.querySelectorAll('.text-gray-300').forEach(d => {
                    d.classList.remove('text-gray-300');
                    d.classList.add('text-white/20');
                });
            }
            cta.classList.add('bg-white', 'text-[var(--color-surface-dark)]', 'hover:bg-orange-400', 'hover:text-white');
            cta.classList.remove('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
        }
    }

    window.addEventListener('scroll', updateHeader, {passive: true});
    updateHeader();

    // Scroll animations
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, {threshold: 0.08, rootMargin: '0px 0px -40px 0px'});

    document.querySelectorAll('[data-animate]').forEach(el => observer.observe(el));
</script>
</body>
</html>
