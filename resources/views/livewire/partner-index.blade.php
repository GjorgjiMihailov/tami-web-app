<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Partners — {{ $company->name }}</h1>

    @can('create', \App\Models\Partner::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add partner</h2>
            <form wire:submit="addPartner" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="Tax ID" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                </div>
                <div>
                    <x-input-label for="newEmail" value="Email" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Phone" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Address" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Tax ID</th>
                <th class="py-2 px-4">Email</th>
                <th class="py-2 px-4">Phone</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($partners as $partner)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $partner->name }}</td>
                    <td class="py-2 px-4">{{ $partner->tax_id }}</td>
                    <td class="py-2 px-4">{{ $partner->email }}</td>
                    <td class="py-2 px-4">{{ $partner->phone }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">No partners yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
