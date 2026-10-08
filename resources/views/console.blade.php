<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FaPost</title>
    {{-- Applies the saved colour theme before the first paint. Same rule as resources/js/ui/shell/theme.ts; keep the storage key equal. --}}
    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('fapost-theme');
                var dark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/ui.css', 'resources/js/console/app.ts'])
    @inertiaHead
</head>
<body>
@inertia
</body>
</html>
