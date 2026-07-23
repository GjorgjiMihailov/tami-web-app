<x-card class="mt-4">
    <h2 class="font-semibold text-gray-700 mb-2">Documents</h2>

    @can('update', $documentable)
        <form wire:submit="upload" class="flex flex-wrap gap-3 items-end mb-4">
            <div>
                <x-input-label for="newFile" value="File" />
                <input type="file" id="newFile" wire:model="newFile" class="text-sm">
                @error('newFile') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="newCategory" value="Category" />
                <select id="newCategory" wire:model="newCategory" class="border-gray-300 rounded-md text-sm">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[12rem]">
                <x-input-label for="newNote" value="Note" />
                <x-text-input id="newNote" wire:model="newNote" class="w-full" />
            </div>
            <x-primary-button type="submit">Upload</x-primary-button>
        </form>
    @endcan

    <table class="min-w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1">File</th>
                <th class="py-1">Category</th>
                <th class="py-1">Note</th>
                <th class="py-1">Uploaded by</th>
                <th class="py-1">Date</th>
                <th class="py-1"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="py-1">
                        <a href="{{ route('documents.download', [$documentable->company_id, $document]) }}" class="text-indigo-600 hover:underline">
                            {{ $document->original_filename }}
                        </a>
                    </td>
                    <td class="py-1">{{ $document->category }}</td>
                    <td class="py-1">{{ $document->note }}</td>
                    <td class="py-1">{{ $document->uploader?->name }}</td>
                    <td class="py-1">{{ $document->created_at->toDateString() }}</td>
                    <td class="py-1">
                        @can('update', $documentable)
                            <button type="button" wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" class="text-red-600 text-sm">Delete</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-2 text-gray-500">No documents attached.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
