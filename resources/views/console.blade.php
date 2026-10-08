<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FaPost</title>
    @vite(['resources/css/ui.css', 'resources/js/console/app.ts'])
    @inertiaHead
</head>
<body>
@inertia
</body>
</html>
