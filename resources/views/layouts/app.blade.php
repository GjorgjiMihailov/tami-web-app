<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        {{-- sidebarOpen lives here so both the sidebar itself and the toggle
             button inside layout.navigation can reach it through Alpine's
             DOM scope. It resets to false on every wire:navigate, which is
             what we want: following a menu link closes the drawer. --}}
        <div x-data="{ sidebarOpen: false }" class="min-h-screen flex bg-canvas">
            <livewire:layout.sidebar />

            {{-- Backdrop, mobile only: tapping outside the drawer closes it. --}}
            <div x-show="sidebarOpen" x-cloak x-transition.opacity
                 @click="sidebarOpen = false"
                 class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden"></div>

            <div class="flex-1 flex flex-col min-w-0">
                <livewire:layout.navigation />

                <!-- Page Heading -->
                @if (isset($header))
                    <header class="bg-white border-b border-gray-100">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content -->
                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
