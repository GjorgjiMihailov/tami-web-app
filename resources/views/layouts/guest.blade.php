<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Support\PortalApp::current()->title() }}</title>

        @include('partials.favicon')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center gap-8 bg-canvas px-4 py-10">
            {{-- Темна плочка: текстот во логото е бел и на светла подлога не се чита. --}}
            <a href="/" class="block rounded-2xl bg-night px-7 py-4 shadow-card">
                <img src="/images/logo.png" alt="ТАМИ — FinanceBuddy App" class="h-14 w-auto" width="800" height="232">
            </a>

            <div class="w-full sm:max-w-md overflow-hidden rounded-2xl border border-sand bg-white shadow-card">
                <div class="h-1 bg-gradient-to-r from-brand to-brand-light"></div>
                <div class="px-6 py-6 sm:px-8 sm:py-8">
                    {{ $slot }}
                </div>
            </div>

            <p class="text-xs text-stone">© {{ date('Y') }} FinanceBuddy.mk</p>
        </div>
    </body>
</html>
