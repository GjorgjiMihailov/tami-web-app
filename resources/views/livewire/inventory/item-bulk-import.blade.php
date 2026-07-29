<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Масовен внес артикли — {{ $company->name }}</h1>

    <x-card class="mb-6">
        <p class="text-sm text-gray-600 mb-3">
            Прво преземете го образецот, пополнете го во Excel и прикачете го овде.
        </p>
        <a href="{{ route('inventory.items.bulk-import.template', $company) }}" class="text-brand text-sm hover:underline">
            Преземи образец
        </a>
    </x-card>
</div>
