<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @can('create', \App\Models\Company::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add company</h2>
            <form wire:submit="addCompany" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="Tax ID" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                    @error('newTaxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newEmail" value="Email" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Phone" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                    @error('newPhone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Address" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                    @error('newAddress') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add company</x-primary-button>
            </form>
        </x-card>
    @endcan

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $company->name }}</span>
                        @can('update', $company)
                            @if ($editingCompanyId !== $company->id)
                                <button type="button" wire:click="startEdit({{ $company->id }})" class="text-brand hover:underline text-sm">Edit settings</button>
                            @endif
                        @endcan
                    </div>

                    @if ($editingCompanyId === $company->id)
                        <div class="mt-2 mb-3 p-3 bg-gray-50 rounded-md">
                            <form wire:submit="saveEdit" class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="editBankAccount" value="Bank account (IBAN)" />
                                    <x-text-input id="editBankAccount" wire:model="editBankAccount" class="w-64" />
                                </div>
                                <div class="flex items-center gap-2 pb-2">
                                    <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                                    <label for="editIsVatRegistered" class="text-sm">VAT registered</label>
                                </div>
                                <x-primary-button type="submit">Save</x-primary-button>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
