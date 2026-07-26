<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Артикли — {{ $company->name }}</h1>

    @can('create', \App\Models\Item::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади артикл</h2>
            <form wire:submit="addItem" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Шифра / баркод" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-40" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newUnitOfMeasure" value="Мерна единица" />
                    <x-text-input id="newUnitOfMeasure" wire:model="newUnitOfMeasure" class="w-24" />
                </div>
                <div>
                    <x-input-label for="newCategory" value="Категорија" />
                    <x-text-input id="newCategory" wire:model="newCategory" class="w-32" />
                </div>
                <div>
                    <x-input-label for="newVatRate" value="Стапка на ДДВ" />
                    <x-text-input id="newVatRate" wire:model="newVatRate" class="w-20" />
                </div>
                <div>
                    <x-input-label for="newPreferredPartnerId" value="Основен добавувач" />
                    <select id="newPreferredPartnerId" wire:model="newPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Пребарувај по назив или шифра" class="w-full max-w-sm" />
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">Мерна единица</th>
                <th class="py-2 px-4">Категорија</th>
                <th class="py-2 px-4">ДДВ %</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm {{ $item->is_active ? '' : 'text-gray-400' }}">
                    <td class="py-2 px-4 font-mono">{{ $item->code }}</td>
                    <td class="py-2 px-4">{{ $item->name }}</td>
                    <td class="py-2 px-4">{{ $item->unit_of_measure }}</td>
                    <td class="py-2 px-4">{{ $item->category }}</td>
                    <td class="py-2 px-4">{{ $item->vat_rate }}</td>
                    <td class="py-2 px-4">{{ $item->is_active ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $item)
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-brand hover:underline text-sm">
                                {{ $item->is_active ? 'Деактивирај' : 'Активирај' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-4 px-4 text-gray-500">Нема додадено артикли.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
