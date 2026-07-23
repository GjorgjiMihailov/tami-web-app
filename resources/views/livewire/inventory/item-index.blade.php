<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Items — {{ $company->name }}</h1>

    @can('create', \App\Models\Item::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add item</h2>
            <form wire:submit="addItem" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Code / barcode" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-40" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newUnitOfMeasure" value="Unit" />
                    <x-text-input id="newUnitOfMeasure" wire:model="newUnitOfMeasure" class="w-24" />
                </div>
                <div>
                    <x-input-label for="newCategory" value="Category" />
                    <x-text-input id="newCategory" wire:model="newCategory" class="w-32" />
                </div>
                <div>
                    <x-input-label for="newVatRate" value="VAT %" />
                    <x-text-input id="newVatRate" wire:model="newVatRate" class="w-20" />
                </div>
                <div>
                    <x-input-label for="newPreferredPartnerId" value="Preferred supplier" />
                    <select id="newPreferredPartnerId" wire:model="newPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Search by name or code" class="w-full max-w-sm" />
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Code</th>
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Unit</th>
                <th class="py-2 px-4">Category</th>
                <th class="py-2 px-4">VAT %</th>
                <th class="py-2 px-4">Active</th>
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
                    <td class="py-2 px-4">{{ $item->is_active ? 'Yes' : 'No' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $item)
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-brand hover:underline text-sm">
                                {{ $item->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-4 px-4 text-gray-500">No items yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
