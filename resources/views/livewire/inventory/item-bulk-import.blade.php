<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Масовен внес артикли — {{ $company->name }}</h1>

    @if ($summary)
        <x-card class="mb-6 bg-green-50">
            <p class="text-sm text-green-700">{{ $summary }}</p>
        </x-card>
    @endif

    <x-card class="mb-6">
        <p class="text-sm text-gray-600 mb-3">
            Прво преземете го образецот, пополнете го во Excel и прикачете го овде.
        </p>
        <a href="{{ route('inventory.items.bulk-import.template', $company) }}" class="text-brand text-sm hover:underline mb-4 block">
            Преземи образец
        </a>

        <form wire:submit="preview" class="flex items-end gap-3">
            <div>
                <x-input-label for="importFile" value="Фајл (.xlsx или .csv)" />
                <input type="file" id="importFile" wire:model="importFile" accept=".xlsx,.csv" class="text-sm">
                @error('importFile') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror
            </div>
            <x-primary-button type="submit">Прикажи преглед</x-primary-button>
        </form>
    </x-card>

    @if (! empty($parsedRows))
        <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-2 px-4">Ред</th>
                        <th class="py-2 px-4">Статус</th>
                        <th class="py-2 px-4">Шифра</th>
                        <th class="py-2 px-4">Назив</th>
                        <th class="py-2 px-4">Мерна единица</th>
                        <th class="py-2 px-4">Категорија</th>
                        <th class="py-2 px-4">ДДВ %</th>
                        <th class="py-2 px-4">Продажна цена</th>
                        <th class="py-2 px-4">Тип</th>
                        <th class="py-2 px-4">МК-производство</th>
                        <th class="py-2 px-4">Баркод</th>
                        <th class="py-2 px-4">Забелешка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($parsedRows as $row)
                        <tr class="text-sm" wire:key="preview-row-{{ $row['row_number'] }}">
                            <td class="py-2 px-4">{{ $row['row_number'] }}</td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'new')
                                    <span class="text-green-700">Ново</span>
                                @elseif ($row['action'] === 'update')
                                    <span class="text-blue-700">Ажурирање</span>
                                @else
                                    <span class="text-red-600">Грешка</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 font-mono">{{ $row['code'] }}</td>
                            <td class="py-2 px-4">{{ $row['name'] }}</td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && $row['unit_of_measure'] === null)
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['unit_of_measure'] }}
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && ! $row['category_provided'])
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['category'] ?? '—' }}
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && $row['vat_rate'] === null)
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['vat_rate'] }}
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && ! $row['selling_price_provided'])
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['selling_price'] !== null ? \App\Support\Format::money($row['selling_price']) : '—' }}
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && $row['type'] === null)
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['type'] !== null ? \App\Support\Format::itemType($row['type']) : '—' }}
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'update' && $row['is_made_in_mk'] === null)
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['is_made_in_mk'] === null ? '—' : ($row['is_made_in_mk'] ? 'Да' : 'Не') }}
                                @endif
                            </td>
                            <td class="py-2 px-4 font-mono">
                                @if ($row['action'] === 'update' && ! $row['barcode_provided'])
                                    <span class="text-gray-400 italic">(без промена)</span>
                                @else
                                    {{ $row['barcode'] ?? '—' }}
                                @endif
                            </td>
                            <td class="py-2 px-4 text-red-600">{{ implode(' ', $row['errors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        @php $hasActionableRows = collect($parsedRows)->contains(fn ($row) => $row['action'] !== 'error'); @endphp
        @if ($hasActionableRows)
            <div class="mt-4">
                <x-primary-button type="button" wire:click="confirmImport">Потврди и зачувај</x-primary-button>
            </div>
        @else
            <p class="text-sm text-gray-500 mt-4">Нема валидни редови за зачувување.</p>
        @endif
    @endif
</div>
