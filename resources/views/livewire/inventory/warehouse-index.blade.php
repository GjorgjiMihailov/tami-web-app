<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Warehouses — {{ $company->name }}</h1>

    @can('create', \App\Models\Warehouse::class)
        <x-card class="mb-6">
            <form wire:submit="addWarehouse" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Warehouse name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Active</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr class="text-sm {{ $warehouse->is_active ? '' : 'text-gray-400' }}">
                    <td class="py-2 px-4">{{ $warehouse->name }}</td>
                    <td class="py-2 px-4">{{ $warehouse->is_active ? 'Yes' : 'No' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $warehouse)
                            <button type="button" wire:click="toggleActive({{ $warehouse->id }})" class="text-brand hover:underline text-sm">
                                {{ $warehouse->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 px-4 text-gray-500">No warehouses yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
