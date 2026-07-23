<div class="w-60 shrink-0 bg-gray-800 text-white flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-700">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Dashboard
        </a>
        <a href="{{ route('companies.index') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('companies.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Companies
        </a>

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-700">
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-400">{{ $company->name }}</div>

                <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('accounting.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Сметководство
                </a>
                <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('inventory.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Магацин
                </a>
                <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Фактури
                </a>
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('documents.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Документи
                </a>
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('reports.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
