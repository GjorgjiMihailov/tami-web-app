<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Нов кооперант — {{ $company->name }}</h1>

    <form wire:submit="save">
        <x-card class="mb-6">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-input-label for="name" value="Назив *" />
                    <x-text-input id="name" wire:model="name" class="w-full" />
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="type" value="Тип" />
                    <select id="type" wire:model="type" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="legal_entity">{{ \App\Support\Format::partnerType('legal_entity') }}</option>
                        <option value="individual">{{ \App\Support\Format::partnerType('individual') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="taxId" value="ЕДБ" />
                    <x-text-input id="taxId" wire:model="taxId" class="w-full" />
                    @error('taxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="email" value="Е-пошта" />
                    <x-text-input id="email" wire:model="email" class="w-full" />
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="phone" value="Телефон" />
                    <x-text-input id="phone" wire:model="phone" class="w-full" />
                    @error('phone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="address" value="Адреса" />
                    <x-text-input id="address" wire:model="address" class="w-full" />
                    @error('address') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <div class="flex items-center gap-4">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ route('partners.index', $company) }}" wire:navigate class="text-gray-500 text-sm hover:underline">Откажи</a>
        </div>
    </form>
</div>
