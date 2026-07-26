<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — Тами</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="text-center px-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">{{ $title }}</h1>
        <p class="text-gray-500 mb-6">{{ $message }}</p>
        <a href="{{ url('/') }}" class="inline-block bg-brand text-white px-4 py-2 rounded-md text-sm">Кон почетна страница</a>
    </div>
</body>
</html>
