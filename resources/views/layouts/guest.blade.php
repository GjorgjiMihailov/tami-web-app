<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Support\PortalApp::current()->title() }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center gap-8 bg-canvas px-4 py-10">
            <a href="/" class="flex items-center gap-2.5">
                <img src="/images/logo-icon.png" alt="" class="h-10 w-10" width="40" height="40">
                <span class="leading-tight">
                    <span class="block text-xl font-bold tracking-tight text-ink">ТАМИ</span>
                    <span class="block text-xs text-stone">FinanceBuddy App</span>
                </span>
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
