<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Залиха — {{ $company->name }}</h1>
        <a href="{{ route('inventory.reports.stock-on-hand.pdf', $company) }}{{ $warehouseId ? '?warehouseId='.$warehouseId : '' }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
    </div>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="warehouseId" value="Магацин" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">Сите магацини (вкупно)</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
    </x-card>

    @if ($warehouseId)
        <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Количина</th>
                    <th class="py-2 px-4 text-right">Просечна цена</th>
                    <th class="py-2 px-4 text-right">Набавна вредност</th>
                    <th class="py-2 px-4 text-right">Продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity_on_hand'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['average_cost'], currency: '', decimals: 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['value'], currency: '') }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема залиха во овој магацин.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @else
        <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Вкупна количина</th>
                    <th class="py-2 px-4 text-right">Вкупна набавна вредност</th>
                    <th class="py-2 px-4 text-right">Вкупна продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($totals as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['total_value'], currency: '') }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['total_selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-4 text-gray-500">Нема евидентирана залиха.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @endif
</div>
