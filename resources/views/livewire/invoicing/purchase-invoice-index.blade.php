<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Влезни фактури — {{ $company->name }}</h1>
        <a href="{{ route('purchase-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Нова влезна фактура</a>
    </div>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border-gray-300 rounded-md text-sm">
            <option value="">Сите статуси</option>
            <option value="draft">Нацрт</option>
            <option value="confirmed">Потврдена</option>
            <option value="cancelled">Откажана</option>
        </select>
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Бр. кај добавувач</th>
                <th class="py-2 px-4">Добавувач</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Статус</th>
                <th class="py-2 px-4">Вкупно</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->supplier_invoice_number }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ ucfirst($invoice->status) }}</x-badge></td>
                    <td class="py-2 px-4">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('purchase-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline">Прегледај</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема внесено влезни фактури.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
