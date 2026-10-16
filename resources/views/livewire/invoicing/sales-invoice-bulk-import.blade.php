<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Масовен внес на излезни фактури — {{ $company->name }}</h1>

    @if ($summary)
        <x-card class="mb-6 bg-green-50">
            <p class="text-sm text-green-700">{{ $summary }}</p>
            <a href="{{ route('sales-invoices.index', $company) }}" class="text-brand text-sm hover:underline mt-2 inline-block">Кон листата на фактури</a>
        </x-card>
    @endif

    <x-card class="mb-6">
        <p class="text-sm text-gray-600 mb-3">
            За фирма што ја преземате од друга програма. Преземете го образецот, внесете по еден ред за секоја фактура (број, датум, кооперант) и прикачете го овде.
            Се создаваат нацрт-фактури без ставки — артиклите и количините ги додавате потоа, на секоја фактура посебно. Увезените фактури не се испраќаат до УЈП.
        </p>
        <a href="{{ route('sales-invoices.bulk-import.template', $company) }}" class="text-brand text-sm hover:underline mb-4 block">
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
        <p class="text-sm text-gray-700 mb-3">
            <span class="text-green-700">{{ $readyCount }} јасни</span>,
            <span class="text-amber-700">{{ $unmatchedCount }} чекаат избор на кооперант</span>,
            <span class="text-red-600">{{ $errorCount }} со грешка (не се увезуваат)</span>.
        </p>

        @if (! empty($groups))
            <x-card class="mb-6">
                <h2 class="text-sm font-semibold text-gray-800 mb-1">Нејасни кооперанти</h2>
                <p class="text-xs text-gray-500 mb-3">Овие имиња не се најдоа меѓу вашите кооперанти. Изборот важи за сите фактури со тоа име.</p>

                <div class="grid gap-3">
                    @foreach ($groups as $key => $group)
                        <div class="flex flex-wrap items-center gap-3" wire:key="group-{{ $key }}">
                            <div class="min-w-[16rem]">
                                <div class="text-sm font-medium text-gray-800">{{ $group['name'] }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ $group['count'] }} {{ $group['count'] === 1 ? 'фактура' : 'фактури' }}@if ($group['tax_id'] !== '') · ЕДБ {{ $group['tax_id'] }}@endif
                                </div>
                            </div>
                            <select wire:model.live="partnerChoices.{{ $key }}" class="border-gray-300 rounded-md shadow-sm text-sm min-w-[18rem]">
                                <option value="">— изберете —</option>
                                <option value="new">Создај нов кооперант со ова име</option>
                                <option value="skip">Прескокни ги овие фактури</option>
                                <optgroup label="Постоечки кооперанти">
                                    @foreach ($partners as $partner)
                                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-1 px-3">Ред</th>
                        <th class="py-1 px-3">Статус</th>
                        <th class="py-1 px-3">Број</th>
                        <th class="py-1 px-3">Датум</th>
                        <th class="py-1 px-3">Кооперант</th>
                        <th class="py-1 px-3">ЕДБ</th>
                        <th class="py-1 px-3 text-right">Вкупно од хартија</th>
                        <th class="py-1 px-3">Забелешка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($parsedRows as $row)
                        <tr class="text-sm hover:bg-orange-50" wire:key="row-{{ $row['row_number'] }}">
                            <td class="py-1 px-3">{{ $row['row_number'] }}</td>
                            <td class="py-1 px-3">
                                @if ($row['status'] === 'ready')
                                    <span class="text-green-700">Јасна</span>
                                @elseif ($row['status'] === 'unmatched')
                                    <span class="text-amber-700">Чека избор</span>
                                @else
                                    <span class="text-red-600">Грешка</span>
                                @endif
                            </td>
                            <td class="py-1 px-3 font-mono">{{ $row['number'] }}</td>
                            <td class="py-1 px-3">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.Y') : '—' }}</td>
                            <td class="py-1 px-3">{{ $row['partner_name'] }}</td>
                            <td class="py-1 px-3 font-mono">{{ $row['tax_id'] }}</td>
                            <td class="py-1 px-3 text-right">{{ $row['total'] !== null ? \App\Support\Format::money($row['total']) : '—' }}</td>
                            <td class="py-1 px-3 text-red-600">{{ implode(' ', $row['errors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        @error('confirm') <p class="text-red-600 text-sm mt-4">{{ $message }}</p> @enderror

        @if ($readyCount + $unmatchedCount > 0)
            <div class="mt-4">
                <x-primary-button type="button" wire:click="confirmImport">Потврди го увозот</x-primary-button>
            </div>
        @else
            <p class="text-sm text-gray-500 mt-4">Нема редови за увоз. Поправете го шаблонот и прикачете го повторно.</p>
        @endif
    @endif
</div>
