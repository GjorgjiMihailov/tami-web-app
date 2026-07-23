<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Stock Valuation — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="groupBy" value="Break down by" />
            <select id="groupBy" wire:model.live="groupBy" class="border-gray-300 rounded-md text-sm">
                <option value="">Total only</option>
                <option value="warehouse">Warehouse</option>
                <option value="category">Category</option>
            </select>
        </div>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">{{ $groupBy ? ucfirst($groupBy) : '' }}</th>
                <th class="py-2 px-4 text-right">Total Value</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $row['label'] }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['total_value'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="py-4 px-4 text-gray-500">No stock recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
