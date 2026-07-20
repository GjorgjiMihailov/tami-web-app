<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Stock On Hand — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="warehouseId" value="Warehouse" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">All warehouses (totals)</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Code</th>
                    <th class="py-2 px-4">Item</th>
                    <th class="py-2 px-4 text-right">Quantity</th>
                    <th class="py-2 px-4 text-right">Avg. Cost</th>
                    <th class="py-2 px-4 text-right">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity_on_hand'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['average_cost'], 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-4 text-gray-500">No stock in this warehouse.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Code</th>
                    <th class="py-2 px-4">Item</th>
                    <th class="py-2 px-4 text-right">Total Quantity</th>
                    <th class="py-2 px-4 text-right">Total Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($totals as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 px-4 text-gray-500">No stock recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>
