<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Артикли — {{ $company->name }}</h1>

    <div class="mb-4">
        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
            Масовен внес преку табела
        </a>
    </div>

    @can('create', \App\Models\Item::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади артикл</h2>
            <form wire:submit="addItem" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Шифра" />
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
                    <x-input-label for="newSellingPrice" value="Продажна цена" />
                    <x-text-input id="newSellingPrice" wire:model="newSellingPrice" class="w-24" />
                    @error('newSellingPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newType" value="Тип" />
                    <select id="newType" wire:model="newType" class="border-gray-300 rounded-md text-sm">
                        <option value="product">Производ</option>
                        <option value="service">Услуга</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" id="newIsMadeInMk" wire:model="newIsMadeInMk">
                    <label for="newIsMadeInMk" class="text-sm">МК-производство</label>
                </div>
                <div>
                    <x-input-label for="newBarcode" value="Баркод" />
                    <x-text-input id="newBarcode" wire:model="newBarcode" class="w-32" />
                    @error('newBarcode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
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
                <th class="py-2 px-4">Продажна цена</th>
                <th class="py-2 px-4">Тип</th>
                <th class="py-2 px-4">МК-производство</th>
                <th class="py-2 px-4">Баркод</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm {{ $item->is_active ? '' : 'text-gray-400' }}" wire:key="item-{{ $item->id }}">
                    <td class="py-2 px-4 font-mono">{{ $item->code }}</td>
                    <td class="py-2 px-4">{{ $item->name }}</td>
                    <td class="py-2 px-4">{{ $item->unit_of_measure }}</td>
                    <td class="py-2 px-4">{{ $item->category }}</td>
                    <td class="py-2 px-4">{{ $item->vat_rate }}</td>
                    <td class="py-2 px-4">{{ $item->selling_price !== null ? \App\Support\Format::money($item->selling_price) : '—' }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::itemType($item->type) }}</td>
                    <td class="py-2 px-4">{{ $item->is_made_in_mk ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4 font-mono">{{ $item->barcode }}</td>
                    <td class="py-2 px-4">{{ $item->is_active ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4 whitespace-nowrap">
                        @can('update', $item)
                            <button type="button" wire:click="startEditingItem({{ $item->id }})" class="text-brand hover:underline text-sm mr-3">Уреди</button>
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-brand hover:underline text-sm">
                                {{ $item->is_active ? 'Деактивирај' : 'Активирај' }}
                            </button>
                        @endcan
                    </td>
                </tr>
                @if ($editingItemId === $item->id)
                    <tr wire:key="item-edit-{{ $item->id }}">
                        <td colspan="11" class="p-4 bg-gray-50">
                            <form wire:submit="updateItem({{ $item->id }})" class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="editCode" value="Шифра" />
                                    <x-text-input id="editCode" wire:model="editCode" class="w-40" />
                                    @error('editCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div class="flex-1 min-w-[12rem]">
                                    <x-input-label for="editName" value="Назив" />
                                    <x-text-input id="editName" wire:model="editName" class="w-full" />
                                    @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editUnitOfMeasure" value="Мерна единица" />
                                    <x-text-input id="editUnitOfMeasure" wire:model="editUnitOfMeasure" class="w-24" />
                                </div>
                                <div>
                                    <x-input-label for="editCategory" value="Категорија" />
                                    <x-text-input id="editCategory" wire:model="editCategory" class="w-32" />
                                </div>
                                <div>
                                    <x-input-label for="editVatRate" value="Стапка на ДДВ" />
                                    <x-text-input id="editVatRate" wire:model="editVatRate" class="w-20" />
                                </div>
                                <div>
                                    <x-input-label for="editSellingPrice" value="Продажна цена" />
                                    <x-text-input id="editSellingPrice" wire:model="editSellingPrice" class="w-24" />
                                    @error('editSellingPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editType" value="Тип" />
                                    <select id="editType" wire:model="editType" class="border-gray-300 rounded-md text-sm">
                                        <option value="product">Производ</option>
                                        <option value="service">Услуга</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2 pb-2">
                                    <input type="checkbox" id="editIsMadeInMk" wire:model="editIsMadeInMk">
                                    <label for="editIsMadeInMk" class="text-sm">МК-производство</label>
                                </div>
                                <div>
                                    <x-input-label for="editBarcode" value="Баркод" />
                                    <x-text-input id="editBarcode" wire:model="editBarcode" class="w-32" />
                                    @error('editBarcode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editPreferredPartnerId" value="Основен добавувач" />
                                    <select id="editPreferredPartnerId" wire:model="editPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                                        <option value="">—</option>
                                        @foreach ($partners as $partner)
                                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-primary-button type="submit">Зачувај</x-primary-button>
                                <button type="button" wire:click="cancelEditingItem" class="text-gray-500 text-sm hover:underline">Откажи</button>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="11" class="py-4 px-4 text-gray-500">Нема додадено артикли.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
