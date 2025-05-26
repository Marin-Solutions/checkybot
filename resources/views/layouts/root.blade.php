<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">

    <title>Checkybot</title>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    @vite('resources/css/filament.css')
</head>

<body class="antialiased bg-blue-950 text-blue-100 font-inter-sans">
{{ $slot }}

<footer class="mt-20">
    <div class="mx-auto max-w-7xl px-6 py-12 md:flex md:items-center md:justify-center lg:px-8">
{{--        <div class="flex justify-center space-x-6 md:order-2">--}}
{{--            <a href="#" class="text-sm">Link 1</a>--}}
{{--            <a href="#" class="text-sm">Link 1</a>--}}
{{--            <a href="#" class="text-sm">Link 1</a>--}}
{{--            <a href="#" class="text-sm">Link 1</a>--}}
{{--            <a href="#" class="text-sm">Link 1</a>--}}
{{--        </div>--}}
        <div class="mt-8 md:order-1 md:mt-0">
            <p class="text-center text-xs leading-5 text-base-gray-500">
                © 2025 CheckybotLabs. All rights reserved.
            </p>
        </div>
    </div>
</footer>

@vite('resources/js/app.js')
</body>
</html>
