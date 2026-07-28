<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Журнали — {{ $company->name }}</h1>

    @error('delete') <p class="text-red-600 text-sm mb-4">{{ $message }}</p> @enderror

    @can('create', \App\Models\JournalGroup::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади журнал</h2>
            <form wire:submit="addGroup" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Код (2 цифри)" />
                    <x-text-input id="newCode" wire:model="newCode" maxlength="2" class="w-20" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Име" />
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
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Код</th>
                <th class="py-2 px-4">Име</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($groups as $group)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $group->code }}</td>
                    <td class="py-2 px-4">{{ $group->name }}</td>
                    <td class="py-2 px-4">
                        @can('delete', $group)
                            <button type="button" wire:click="deleteGroup({{ $group->id }})" wire:confirm="Да се избрише журналот {{ $group->code }}?" class="text-red-600 text-sm hover:underline">Избриши</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 px-4 text-gray-500">Нема додадено журнали.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
