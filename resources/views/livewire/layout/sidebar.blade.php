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

                {{-- Accounting --}}
                <button type="button" wire:click="toggleModule('accounting')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ request()->routeIs('accounting.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Сметководство</span>
                    <span>{{ $expandedModule === 'accounting' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'accounting')
                    <div class="pl-6">
                        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.accounts.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Accounts</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-entries.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Journal</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.ledger-card') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Ledger Card</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.trial-balance') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Trial Balance</a>
                    </div>
                @endif

                {{-- Inventory --}}
                <button type="button" wire:click="toggleModule('inventory')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ request()->routeIs('inventory.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Магацин</span>
                    <span>{{ $expandedModule === 'inventory' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'inventory')
                    <div class="pl-6">
                        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.warehouses.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Warehouses</a>
                        <a href="{{ route('inventory.items.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Items</a>
                        <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-on-hand') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Stock On Hand</a>
                        <a href="{{ route('inventory.reports.item-movement-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.item-movement-card') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Item Movement Card</a>
                        <a href="{{ route('inventory.reports.stock-valuation', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-valuation') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Stock Valuation</a>

                        <button type="button" wire:click="toggleRecordMovement"
                                class="w-full text-left flex items-center justify-between px-4 py-1.5 text-sm {{ request()->routeIs('inventory.stock-movements.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">
                            <span>Record Movement</span>
                            <span>{{ $recordMovementExpanded ? '−' : '+' }}</span>
                        </button>
                        @if ($recordMovementExpanded)
                            <div class="pl-4">
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'receipt']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Receipt</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'issue']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Issue</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'transfer']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Transfer</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'adjustment']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Adjustment</a>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Invoicing --}}
                <button type="button" wire:click="toggleModule('invoicing')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Фактури</span>
                    <span>{{ $expandedModule === 'invoicing' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'invoicing')
                    <div class="pl-6">
                        <a href="{{ route('partners.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('partners.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Partners</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.index') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Sales Invoices</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">New Invoice</a>
                        <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.index') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Purchase Invoices</a>
                        <a href="{{ route('purchase-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">New Purchase Invoice</a>
                    </div>
                @endif

                {{-- Documents (no submenu) --}}
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('documents.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Документи
                </a>

                {{-- Reports (no submenu) --}}
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('reports.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
