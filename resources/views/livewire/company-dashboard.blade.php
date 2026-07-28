<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold text-gray-800">Работите на: {{ $company->name }}</h1>
        @can('update', $company)
            @if (! $editing)
                <button type="button" wire:click="startEdit" class="text-brand hover:underline text-sm">Уреди</button>
            @endif
        @endcan
    </div>
    <p class="text-sm text-gray-500 mb-6">Изберете модул подолу за да започнете.</p>

    @can('update', $company)
        @if ($editing)
            <x-card class="mb-6">
                <h2 class="font-semibold text-gray-700 mb-3">Профил на фирма</h2>
                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="editName" value="Назив" />
                            <x-text-input id="editName" wire:model="editName" class="w-full" />
                            @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label for="editShortName" value="Кратко име" />
                            <x-text-input id="editShortName" wire:model="editShortName" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editTaxId" value="ЕДБ" />
                            <x-text-input id="editTaxId" wire:model="editTaxId" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editRegistrationNumber" value="ЕМБС" />
                            <x-text-input id="editRegistrationNumber" wire:model="editRegistrationNumber" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editNkdCode" value="Шифра на дејност (НКД)" />
                            <x-text-input id="editNkdCode" wire:model="editNkdCode" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editNkdName" value="Назив на дејност (НКД)" />
                            <x-text-input id="editNkdName" wire:model="editNkdName" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editEmail" value="Е-пошта" />
                            <x-text-input id="editEmail" wire:model="editEmail" class="w-full" />
                            @error('editEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label for="editPhone" value="Телефон" />
                            <x-text-input id="editPhone" wire:model="editPhone" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editWebsite" value="Веб-страница" />
                            <x-text-input id="editWebsite" wire:model="editWebsite" class="w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="editAddress" value="Адреса" />
                            <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editDirectorName" value="Управител - име" />
                            <x-text-input id="editDirectorName" wire:model="editDirectorName" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editDirectorPhone" value="Управител - телефон" />
                            <x-text-input id="editDirectorPhone" wire:model="editDirectorPhone" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editDirectorEmail" value="Управител - е-пошта" />
                            <x-text-input id="editDirectorEmail" wire:model="editDirectorEmail" class="w-full" />
                            @error('editDirectorEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex items-center gap-2 pb-2">
                            <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                            <label for="editIsVatRegistered" class="text-sm">Во ДДВ систем</label>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <x-primary-button type="submit">Зачувај</x-primary-button>
                        <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 hover:underline">Откажи</button>
                    </div>
                </form>
            </x-card>
        @endif
    @endcan

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Контен план, налози, картици, биланс</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Магацини, артикли, извештаи за залихи</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Партнери, излезни и влезни фактури</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Прикачени и генерирани документи</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Законски извештаи</p>
            </x-card>
        </a>
    </div>
</div>
