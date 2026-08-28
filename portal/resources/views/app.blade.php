<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'StockPilot') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="h-full bg-gray-100 antialiased">
    <div id="app"></div>
</body>
</html>
