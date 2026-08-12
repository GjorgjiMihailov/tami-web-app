<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Кооперанти — {{ $company->name }}</h1>
        <a href="{{ route('partners.pdf', $company) }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
    </div>

    @can('create', \App\Models\Partner::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади партнер</h2>
            <form wire:submit="addPartner" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newType" value="Тип" />
                    <select id="newType" wire:model="newType" class="border-gray-300 rounded-md text-sm">
                        <option value="legal_entity">{{ \App\Support\Format::partnerType('legal_entity') }}</option>
                        <option value="individual">{{ \App\Support\Format::partnerType('individual') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="newTaxId" value="ЕДБ" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                </div>
                <div>
                    <x-input-label for="newEmail" value="Е-пошта" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Телефон" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Адреса" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">ЕДБ</th>
                <th class="py-2 px-4">Е-пошта</th>
                <th class="py-2 px-4">Телефон</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($partners as $partner)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4"><a href="{{ route('partners.show', [$company, $partner]) }}" class="text-brand hover:underline font-medium">{{ $partner->name }}</a></td>
                    <td class="py-2 px-4">{{ $partner->tax_id }}</td>
                    <td class="py-2 px-4">{{ $partner->email }}</td>
                    <td class="py-2 px-4">{{ $partner->phone }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">Нема додадено партнери.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
