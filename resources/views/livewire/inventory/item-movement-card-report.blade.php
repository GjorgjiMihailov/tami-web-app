<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Item Movement Card — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="itemId" value="Item" />
            <select id="itemId" wire:model.live="itemId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="warehouseId" value="Warehouse" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" value="From" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="To" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </div>

    @if ($itemId && $warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Date</th>
                    <th class="py-2 px-4">Type</th>
                    <th class="py-2 px-4">Counterpart</th>
                    <th class="py-2 px-4 text-right">Quantity</th>
                    <th class="py-2 px-4 text-right">Unit Cost</th>
                    <th class="py-2 px-4 text-right">Running Qty</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}</td>
                        <td class="py-2 px-4">{{ ucfirst($row['type']) }}{{ $row['reason'] ? ' — '.$row['reason'] : '' }}</td>
                        <td class="py-2 px-4">{{ $row['counterpart_warehouse'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['unit_cost'], 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['running_quantity'], 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">No movements in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <p class="text-gray-500">Select an item and a warehouse to see the movement card.</p>
    @endif
</div>
