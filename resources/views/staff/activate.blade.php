<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Set password') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased flex items-center justify-center p-6">
    <div class="w-full max-w-md space-y-6">
        <h1 class="text-xl font-semibold">{{ __('Set your password') }}</h1>
        <p class="text-sm text-zinc-400">{{ $email }}</p>

        @if ($errors->any())
            <ul class="text-sm text-red-400 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="post" action="{{ route('activate.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label class="block text-sm text-zinc-400 mb-1" for="password">{{ __('Password') }}</label>
                <input class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2" type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div>
                <label class="block text-sm text-zinc-400 mb-1" for="password_confirmation">{{ __('Confirm password') }}</label>
                <input class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="w-full rounded bg-amber-500 px-4 py-2 font-medium text-zinc-950 hover:bg-amber-400">
                {{ __('Activate') }}
            </button>
        </form>
    </div>
</body>
</html>
