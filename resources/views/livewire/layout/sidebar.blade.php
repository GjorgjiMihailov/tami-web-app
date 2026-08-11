<div class="w-60 shrink-0 bg-white border-r border-gray-100 text-gray-700 flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-100">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('dashboard') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
            Почетна
        </a>
        <a href="{{ route('companies.index') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('companies.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
            Фирми
        </a>

        @if (auth()->check() && auth()->user()->hasRole('admin'))
            <a href="{{ route('efaktura.access-requests') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('efaktura.access-requests') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                е-Фактура барања
            </a>
        @endif

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-100">
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-500">{{ $company->name }}</div>

                {{-- Accounting --}}
                <button type="button" wire:click="toggleModule('accounting')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('accounting.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Сметководство</span>
                    <span>{{ $expandedModule === 'accounting' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'accounting')
                    <div class="pl-6">
                        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.accounts.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Контен план</a>
                        <a href="{{ route('accounting.journal-groups.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-groups.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Журнали</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-entries.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Налози</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.ledger-card') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Аналитичка картица</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.trial-balance') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Бруто биланс</a>
                    </div>
                @endif

                {{-- Inventory --}}
                <button type="button" wire:click="toggleModule('inventory')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('inventory.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Магацин</span>
                    <span>{{ $expandedModule === 'inventory' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'inventory')
                    <div class="pl-6">
                        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.warehouses.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Магацини</a>
                        <a href="{{ route('inventory.items.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Артикли</a>
                        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.bulk-import') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Масовен внес артикли</a>
                        <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-on-hand') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Залиха</a>
                        <a href="{{ route('inventory.reports.item-movement-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.item-movement-card') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Картица на движење</a>
                        <a href="{{ route('inventory.reports.stock-valuation', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-valuation') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Вреднување на залихи</a>

                        <button type="button" wire:click="toggleRecordMovement"
                                class="w-full text-left flex items-center justify-between px-4 py-1.5 text-sm {{ request()->routeIs('inventory.stock-movements.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">
                            <span>Движење на залиха</span>
                            <span>{{ $recordMovementExpanded ? '−' : '+' }}</span>
                        </button>
                        @if ($recordMovementExpanded)
                            <div class="pl-4">
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'receipt']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Прием</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'issue']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Издавање</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'transfer']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Трансфер</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'adjustment']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Корекција</a>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Invoicing --}}
                <button type="button" wire:click="toggleModule('invoicing')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Фактури</span>
                    <span>{{ $expandedModule === 'invoicing' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'invoicing')
                    <div class="pl-6">
                        <a href="{{ route('partners.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('partners.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Партнери</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Излезни фактури</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Нова фактура</a>
                        <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Влезни фактури</a>
                        <a href="{{ route('purchase-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Нова влезна фактура</a>
                    </div>
                @endif

                {{-- Documents (no submenu) --}}
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('documents.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Документи
                </a>

                {{-- Reports (no submenu) --}}
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('reports.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
