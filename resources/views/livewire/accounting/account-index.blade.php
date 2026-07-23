<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Chart of Accounts — {{ $company->name }}</h1>

    @can('create', \App\Models\Account::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add analytical account</h2>
            <form wire:submit="addAnalyticalAccount" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newParentCode" value="Parent synthetic code (3 digits)" />
                    <x-text-input id="newParentCode" wire:model="newParentCode" class="w-32" />
                    @error('newParentCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newCode" value="New code (4+ digits)" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-32" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    @foreach ($accountsByClass as $class => $accounts)
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-700 border-b pb-1 mb-2">Класа {{ $class }}</h3>
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-1 pr-4">Code</th>
                        <th class="py-1 pr-4">Name</th>
                        <th class="py-1 pr-4">Active</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $account)
                        <tr class="text-sm {{ $account->is_active ? '' : 'text-gray-400' }}">
                            <td class="py-1 pr-4 font-mono">{{ $account->code }}</td>
                            <td class="py-1 pr-4">{{ $account->name }}</td>
                            <td class="py-1 pr-4">{{ $account->is_active ? 'Yes' : 'No' }}</td>
                            <td class="py-1">
                                @can('update', $account)
                                    <button type="button" wire:click="toggleActive({{ $account->id }})" class="text-brand hover:underline text-sm">
                                        {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
