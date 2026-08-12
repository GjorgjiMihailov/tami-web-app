<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">
            {{ $journalGroup ? $journalGroup->code.' — '.$journalGroup->name : 'Налози за книжење' }} — {{ $company->name }}
        </h1>
        @can('create', \App\Models\JournalEntry::class)
            <a href="{{ route('accounting.journal-entries.create', $company) }}">
                <x-primary-button type="button">Нов налог</x-primary-button>
            </a>
        @endcan
    </div>

    @if ($journalGroup)
        <div class="mb-4">
            <a href="{{ route('accounting.journal-groups.index', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
                ← Назад на Главна книга
            </a>
        </div>
    @endif

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">#</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Опис</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4 font-mono">{{ $entry->displayNumber() }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::date($entry->entry_date) }}</td>
                    <td class="py-2 px-4">{{ $entry->description }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('accounting.journal-entries.edit', [$company, $entry]) }}" class="text-brand hover:underline">
                            @can('update', $entry) Измени @else Прегледај @endcan
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
