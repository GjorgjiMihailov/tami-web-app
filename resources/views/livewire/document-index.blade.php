<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Documents — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <x-input-label for="categoryFilter" value="Category" />
            <select id="categoryFilter" wire:model.live="categoryFilter" class="border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}">{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="typeFilter" value="Record type" />
            <select id="typeFilter" wire:model.live="typeFilter" class="border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach ($types as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="fromDate" value="From" />
            <x-text-input id="fromDate" type="date" wire:model.live="fromDate" class="w-full" />
        </div>
        <div>
            <x-input-label for="toDate" value="To" />
            <x-text-input id="toDate" type="date" wire:model.live="toDate" class="w-full" />
        </div>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">File</th>
                <th class="py-2 px-4">Category</th>
                <th class="py-2 px-4">Record</th>
                <th class="py-2 px-4">Uploaded by</th>
                <th class="py-2 px-4">Date</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($documents as $document)
                @php
                    $recordUrl = match ($document->documentable_type) {
                        'purchase_invoice' => route('purchase-invoices.show', [$company, $document->documentable_id]),
                        'sales_invoice' => route('sales-invoices.show', [$company, $document->documentable_id]),
                        'journal_entry' => route('accounting.journal-entries.edit', [$company, $document->documentable_id]),
                        'partner' => route('partners.show', [$company, $document->documentable_id]),
                        default => null,
                    };
                @endphp
                <tr class="text-sm">
                    <td class="py-2 px-4">
                        <a href="{{ route('documents.download', [$company, $document]) }}" class="text-indigo-600 hover:underline">{{ $document->original_filename }}</a>
                    </td>
                    <td class="py-2 px-4">{{ $document->category }}</td>
                    <td class="py-2 px-4">
                        @if ($recordUrl)
                            <a href="{{ $recordUrl }}" class="text-indigo-600 hover:underline">{{ $types[$document->documentable_type] }}</a>
                        @endif
                    </td>
                    <td class="py-2 px-4">{{ $document->uploader?->name }}</td>
                    <td class="py-2 px-4">{{ $document->created_at->toDateString() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 px-4 text-gray-500">No documents yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
