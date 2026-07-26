<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Картица на движење — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="itemId" value="Артикл" />
            <select id="itemId" wire:model.live="itemId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="warehouseId" value="Магацин" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" value="Од" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="До" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </x-card>

    @if ($itemId && $warehouseId)
        <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Датум</th>
                    <th class="py-2 px-4">Тип</th>
                    <th class="py-2 px-4">Спротивна страна</th>
                    <th class="py-2 px-4 text-right">Количина</th>
                    <th class="py-2 px-4 text-right">Единечна цена</th>
                    <th class="py-2 px-4 text-right">Тековна количина</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \App\Support\Format::date($row['date']) }}</td>
                        <td class="py-2 px-4">{{ \App\Support\Format::movementType($row['type']) }}{{ $row['reason'] ? ' — '.$row['reason'] : '' }}</td>
                        <td class="py-2 px-4">{{ $row['counterpart_warehouse'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['unit_cost'], currency: '', decimals: 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['running_quantity'], 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема движења во овој период.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @else
        <p class="text-gray-500">Изберете артикл и магацин за да ја видите картицата на движење.</p>
    @endif
</div>
