<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Магацини — {{ $company->name }}</h1>

    @can('create', \App\Models\Warehouse::class)
        <x-card class="mb-6">
            <form wire:submit="addWarehouse" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив на магацин" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
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
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr class="text-sm hover:bg-orange-50 {{ $warehouse->is_active ? '' : 'text-gray-400' }}">
                    <td class="py-2 px-4">{{ $warehouse->name }}</td>
                    <td class="py-2 px-4">{{ $warehouse->is_active ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $warehouse)
                            <button type="button" wire:click="toggleActive({{ $warehouse->id }})" class="text-brand hover:underline text-sm">
                                {{ $warehouse->is_active ? 'Деактивирај' : 'Активирај' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 px-4 text-gray-500">Нема додадено магацини.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
