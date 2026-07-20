<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        Record {{ ucfirst($type) }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="bg-white shadow rounded-md p-4 flex flex-wrap gap-4 items-end max-w-3xl">
        <div class="w-full">
            <x-input-label for="itemId" value="Item" />
            <select id="itemId" wire:model="itemId" class="border-gray-300 rounded-md text-sm w-full">
                <option value="">—</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                @endforeach
            </select>
            @error('itemId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            @error('scannedCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <x-input-label for="warehouseId" value="{{ $type === 'transfer' ? 'From warehouse' : 'Warehouse' }}" />
            <select id="warehouseId" wire:model="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
            @error('warehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        @if ($type === 'transfer')
            <div>
                <x-input-label for="toWarehouseId" value="To warehouse" />
                <select id="toWarehouseId" wire:model="toWarehouseId" class="border-gray-300 rounded-md text-sm">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('toWarehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        @if ($type === 'adjustment')
            <div>
                <x-input-label for="direction" value="Direction" />
                <select id="direction" wire:model="direction" class="border-gray-300 rounded-md text-sm">
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
            </div>
        @endif

        <div>
            <x-input-label for="quantity" value="Quantity" />
            <x-text-input id="quantity" wire:model="quantity" class="w-32" />
            @error('quantity') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        @if ($type === 'receipt')
            <div>
                <x-input-label for="unitCost" value="Unit cost" />
                <x-text-input id="unitCost" wire:model="unitCost" class="w-32" />
                @error('unitCost') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        @if ($type === 'adjustment')
            <div class="flex-1 min-w-[16rem]">
                <x-input-label for="reason" value="Reason" />
                <x-text-input id="reason" wire:model="reason" class="w-full" />
                @error('reason') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        <div>
            <x-input-label for="movementDate" value="Date" />
            <input type="date" id="movementDate" wire:model="movementDate" class="border-gray-300 rounded-md text-sm" />
            @error('movementDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <x-primary-button type="submit">Save</x-primary-button>
    </form>
</div>
