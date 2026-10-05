<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Link expired') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased flex items-center justify-center p-6">
    <div class="w-full max-w-md space-y-4 text-center">
        <h1 class="text-xl font-semibold">{{ __('This activation link has expired') }}</h1>
        <p class="text-sm text-zinc-400">{{ __('Ask an administrator to resend the invitation.') }}</p>
        <a href="{{ url('/') }}" class="inline-block text-amber-400 hover:underline">{{ __('Back') }}</a>
    </div>
</body>
</html>
