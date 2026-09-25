<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $item ? 'Уреди артикл' : 'Нов артикл' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" x-data="{ sellable: @entangle('isSellable'), purchasable: @entangle('isPurchasable') }">
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Основни податоци</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div class="md:col-span-2">
                    <x-input-label for="name" value="Назив *" />
                    <x-text-input id="name" wire:model="name" class="w-full" />
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="type" value="Тип" />
                    <select id="type" wire:model.live="type" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="product">Производ</option>
                        <option value="service">Услуга</option>
                    </select>
                    @if ($type === 'service')
                        <span class="text-gray-500 text-xs">Услугите не се водат по залиха.</span>
                    @endif
                </div>
                <div>
                    <x-input-label for="category" value="Категорија" />
                    <x-text-input id="category" wire:model="category" list="category-list" class="w-full" placeholder="Избери или впиши нова" />
                    <datalist id="category-list">
                        @foreach ($categories as $value)
                            <option value="{{ $value }}"></option>
                        @endforeach
                    </datalist>
                    @error('category') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="unitOfMeasure" value="Мерна единица *" />
                    <x-text-input id="unitOfMeasure" wire:model="unitOfMeasure" list="unit-list" class="w-full" placeholder="Избери или впиши нова" />
                    <datalist id="unit-list">
                        @foreach ($units as $value)
                            <option value="{{ $value }}"></option>
                        @endforeach
                    </datalist>
                    @error('unitOfMeasure') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" id="isMadeInMk" wire:model="isMadeInMk">
                    <label for="isMadeInMk" class="text-sm">МК-производство</label>
                </div>
                <div>
                    <x-input-label for="code" value="Шифра *" />
                    <x-text-input id="code" wire:model="code" class="w-full" />
                    @error('code') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="barcode" value="Баркод" />
                    <x-text-input id="barcode" wire:model="barcode" class="w-full" />
                    @error('barcode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Опис</h2>
            <textarea id="description" wire:model="description" rows="3" class="border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm w-full text-sm"></textarea>
            @error('description') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </x-card>

        <x-card class="mb-6">
            <label class="flex items-center gap-2 mb-3">
                <input type="checkbox" id="isSellable" x-model="sellable">
                <span class="font-semibold text-gray-700">Продажба</span>
            </label>
            <div class="grid gap-3 md:grid-cols-3" x-show="sellable">
                <div>
                    <x-input-label for="sellingPrice" value="Продажна цена (нето)" />
                    <x-text-input id="sellingPrice" wire:model="sellingPrice" class="w-full" />
                    @error('sellingPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="vatRate" value="ДДВ % при продажба" />
                    <x-text-input id="vatRate" wire:model="vatRate" class="w-full" />
                    @error('vatRate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-gray-500 text-sm" x-show="! sellable">Артиклот нема да се нуди на излезни фактури.</p>
        </x-card>

        <x-card class="mb-6">
            <label class="flex items-center gap-2 mb-3">
                <input type="checkbox" id="isPurchasable" x-model="purchasable">
                <span class="font-semibold text-gray-700">Набавка</span>
            </label>
            <div class="grid gap-3 md:grid-cols-3" x-show="purchasable">
                <div>
                    <x-input-label for="costPrice" value="Набавна цена (нето)" />
                    <x-text-input id="costPrice" wire:model="costPrice" class="w-full" />
                    @error('costPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="purchaseVatRate" value="ДДВ % при набавка" />
                    <x-text-input id="purchaseVatRate" wire:model="purchaseVatRate" class="w-full" placeholder="иста како при продажба" />
                    @error('purchaseVatRate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="preferredPartnerId" value="Основен добавувач" />
                    <select id="preferredPartnerId" wire:model="preferredPartnerId" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                    @error('preferredPartnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-gray-500 text-sm" x-show="! purchasable">Артиклот нема да се нуди на влезни фактури.</p>
        </x-card>

        <div class="flex items-center gap-4">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ route('inventory.items.index', $company) }}" wire:navigate class="text-gray-500 text-sm hover:underline">Откажи</a>
        </div>
    </form>
</div>
