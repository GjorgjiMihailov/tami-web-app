<x-guest-layout>
    <div class="text-center space-y-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ $app->label() }}</h1>

        <p class="text-sm text-gray-600">{{ $message }}</p>

        <a href="{{ route('companies.index') }}"
           class="inline-block px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">
            Назад кон порталот
        </a>
    </div>
</x-guest-layout>
