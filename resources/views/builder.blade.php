<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Builder — FAPost</title>
    @vite(['resources/css/app.css', 'resources/js/builder/app.js'])
    @inertiaHead
</head>
<body class="bg-gray-50">
@inertia
</body>
</html>
