<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @can('create', \App\Models\Company::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add company</h2>
            <form wire:submit="addCompany" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="Tax ID" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                    @error('newTaxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newEmail" value="Email" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Phone" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                    @error('newPhone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Address" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                    @error('newAddress') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add company</x-primary-button>
            </form>
        </div>
    @endcan

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $company->name }}</span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">Accounting:</div>
                    <div class="space-x-3 text-sm">
                        <a href="{{ route('accounting.accounts.index', $company) }}" class="text-indigo-600 hover:underline">Accounts</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" class="text-indigo-600 hover:underline">Journal</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" class="text-indigo-600 hover:underline">Ledger Card</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" class="text-indigo-600 hover:underline">Trial Balance</a>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">Inventory:</div>
                    <div class="space-x-3 text-sm">
                        <a href="{{ route('inventory.warehouses.index', $company) }}" class="text-indigo-600 hover:underline">Warehouses</a>
                        <a href="{{ route('inventory.items.index', $company) }}" class="text-indigo-600 hover:underline">Items</a>
                        <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" class="text-indigo-600 hover:underline">Stock On Hand</a>
                        <a href="{{ route('inventory.reports.item-movement-card', $company) }}" class="text-indigo-600 hover:underline">Item Movement Card</a>
                        <a href="{{ route('inventory.reports.stock-valuation', $company) }}" class="text-indigo-600 hover:underline">Stock Valuation</a>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">Record movement:</div>
                    <div class="space-x-3 text-sm">
                        <a href="{{ route('inventory.stock-movements.create', [$company, 'receipt']) }}" class="text-indigo-600 hover:underline">Receipt</a>
                        <a href="{{ route('inventory.stock-movements.create', [$company, 'issue']) }}" class="text-indigo-600 hover:underline">Issue</a>
                        <a href="{{ route('inventory.stock-movements.create', [$company, 'transfer']) }}" class="text-indigo-600 hover:underline">Transfer</a>
                        <a href="{{ route('inventory.stock-movements.create', [$company, 'adjustment']) }}" class="text-indigo-600 hover:underline">Adjustment</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
