<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Journal Entries — {{ $company->name }}</h1>
        @can('create', \App\Models\JournalEntry::class)
            <a href="{{ route('accounting.journal-entries.create', $company) }}">
                <x-primary-button type="button">New Entry</x-primary-button>
            </a>
        @endcan
    </div>

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">#</th>
                <th class="py-2 px-4">Date</th>
                <th class="py-2 px-4">Description</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $entry->entry_number }}</td>
                    <td class="py-2 px-4">{{ $entry->entry_date->format('d.m.Y') }}</td>
                    <td class="py-2 px-4">{{ $entry->description }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('accounting.journal-entries.edit', [$company, $entry]) }}" class="text-indigo-600 hover:underline">
                            @can('update', $entry) Edit @else View @endcan
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">No journal entries yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
