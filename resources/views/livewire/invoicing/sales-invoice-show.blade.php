<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
        {{ $invoice->status === 'confirmed' ? "Invoice {$invoice->fiscal_year}/{$invoice->invoice_number}" : 'Draft invoice' }}
    </h1>
    <p class="text-sm text-gray-500 mb-4">{{ $invoice->partner->name }} — status: {{ $invoice->status }}
        @if ($invoice->status === 'confirmed') ({{ $invoice->paymentStatus() }}@if($invoice->isOverdue()), overdue @endif) @endif
    </p>

    @error('confirm') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @error('cancel') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Description</th>
                    <th class="py-1">Qty</th>
                    <th class="py-1">Unit price</th>
                    <th class="py-1">VAT %</th>
                    <th class="py-1">Line total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->quantity }}</td>
                        <td class="py-1">{{ $line->unit_price }}</td>
                        <td class="py-1">{{ $line->vat_rate }}</td>
                        <td class="py-1">{{ $line->lineTotal() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="text-right text-sm mt-3 space-y-1">
            <div>Subtotal: {{ $invoice->subtotal() }}</div>
            <div>VAT: {{ $invoice->vatTotal() }}</div>
            <div class="font-semibold">Total: {{ $invoice->grandTotal() }}</div>
            @if ($invoice->status === 'confirmed')
                <div>Balance due: {{ $invoice->balanceDue() }}</div>
            @endif
        </div>
    </div>

    <div class="flex gap-3 mb-4">
        @if ($invoice->status === 'draft')
            <a href="{{ route('sales-invoices.edit', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Edit</a>
            <button type="button" wire:click="confirm" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">Confirm</button>
        @endif
        @if ($invoice->status === 'confirmed')
            <a href="{{ route('sales-invoices.pdf', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Download PDF</a>
            @if (! $invoice->sent_at)
                <button type="button" wire:click="markSent" class="text-indigo-600 hover:underline text-sm">Mark as sent</button>
            @endif
            @if ($invoice->payments->isEmpty())
                <button type="button" wire:click="cancel" class="text-red-600 hover:underline text-sm">Cancel invoice</button>
            @endif
        @endif
    </div>

    @if ($invoice->status === 'confirmed')
        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-2">Payments</h2>
            <table class="min-w-full text-sm mb-3">
                <tbody>
                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td class="py-1">{{ $payment->payment_date->toDateString() }}</td>
                            <td class="py-1">{{ ucfirst($payment->payment_method) }}</td>
                            <td class="py-1">{{ $payment->amount }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($invoice->paymentStatus() !== 'paid')
                <form wire:submit="recordPayment" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <x-input-label for="paymentAmount" value="Amount" />
                        <x-text-input id="paymentAmount" wire:model="paymentAmount" class="w-32" />
                        @error('paymentAmount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="paymentDate" value="Date" />
                        <x-text-input id="paymentDate" type="date" wire:model="paymentDate" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="paymentMethod" value="Method" />
                        <select id="paymentMethod" wire:model="paymentMethod" class="border-gray-300 rounded-md text-sm">
                            <option value="bank">Bank</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                    <x-primary-button type="submit">Record payment</x-primary-button>
                </form>
            @endif
        </div>
    @endif

    <livewire:document-manager :documentable="$invoice" />
</div>
