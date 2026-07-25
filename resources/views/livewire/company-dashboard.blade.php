<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Working on: {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-6">Pick a module below to get started.</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Accounts, journal, ledger, trial balance</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Warehouses, items, stock reports</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Partners, sales and purchase invoices</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Uploaded and generated documents</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Statutory reports</p>
            </x-card>
        </a>
    </div>
</div>
