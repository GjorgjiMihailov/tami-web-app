<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
        Влезна фактура — {{ $invoice->partner->name }} #{{ $invoice->supplier_invoice_number }}
    </h1>
    <p class="text-sm text-gray-500 mb-4 flex items-center gap-2">
        <x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge>
        @if ($invoice->status === 'confirmed')
            <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->paymentStatus()">
                {{ $invoice->isOverdue() ? 'Задоцнета' : \App\Support\Format::paymentStatus($invoice->paymentStatus()) }}
            </x-badge>
        @endif
        @if ($invoice->invoice_date->year !== $workingYear)
            <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">Запис од {{ $invoice->invoice_date->year }}</span>
        @endif
    </p>

    @error('confirm') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @error('cancel') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

    <x-card class="mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Артикл/Сметка</th>
                    <th class="py-1">Кол.</th>
                    <th class="py-1">Ед. цена</th>
                    <th class="py-1">ДДВ %</th>
                    <th class="py-1">Вкупно за ставка</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->item?->name ?? $line->account?->code.' — '.$line->account?->name }}</td>
                        <td class="py-1">{{ $line->quantity }}</td>
                        <td class="py-1">{{ \App\Support\Format::money($line->unit_price) }}</td>
                        <td class="py-1">
                            {{ $line->vat_rate }}{{ $line->vat_deductible ? '' : ' (не се одбива)' }}
                            @if ($line->needs_review)
                                <x-badge status="pending" title="ДДВ стапката не можеше автоматски да се утврди — проверете рачно">⚠</x-badge>
                            @endif
                        </td>
                        <td class="py-1">{{ \App\Support\Format::money($line->lineTotal()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="text-right text-sm mt-3 space-y-1">
            <div>Основа: {{ \App\Support\Format::money($invoice->subtotal()) }}</div>
            <div>ДДВ: {{ \App\Support\Format::money($invoice->vatTotal()) }}</div>
            <div class="font-semibold">Вкупно: {{ \App\Support\Format::money($invoice->grandTotal()) }}</div>
            @if ($invoice->status === 'confirmed')
                <div>За доплата: {{ \App\Support\Format::money($invoice->balanceDue()) }}</div>
            @endif
        </div>
    </x-card>

    <div class="flex gap-3 mb-4">
        @if ($invoice->status === 'draft')
            <a href="{{ route('purchase-invoices.edit', [$company, $invoice]) }}" class="text-brand hover:underline text-sm">Измени</a>
            <button type="button" wire:click="confirm" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Потврди</button>
        @endif
        @if ($invoice->status === 'confirmed' && $invoice->payments->isEmpty())
            <button type="button" wire:click="cancel" class="text-red-600 hover:underline text-sm">Откажи фактура</button>
        @endif
    </div>

    @if ($invoice->status === 'confirmed')
        <x-card>
            <h2 class="font-semibold text-gray-700 mb-2">Плаќања</h2>
            <table class="min-w-full text-sm mb-3">
                <tbody>
                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td class="py-1">{{ \App\Support\Format::date($payment->payment_date) }}</td>
                            <td class="py-1">{{ \App\Support\Format::paymentMethod($payment->payment_method) }}</td>
                            <td class="py-1">{{ \App\Support\Format::money($payment->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($invoice->paymentStatus() !== 'paid')
                <form wire:submit="recordPayment" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <x-input-label for="paymentAmount" value="Износ" />
                        <x-text-input id="paymentAmount" wire:model="paymentAmount" class="w-32" />
                        @error('paymentAmount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="paymentDate" value="Датум" />
                        <x-text-input id="paymentDate" type="date" wire:model="paymentDate" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="paymentMethod" value="Начин" />
                        <select id="paymentMethod" wire:model="paymentMethod" class="border-gray-300 rounded-md text-sm">
                            <option value="bank">Банка</option>
                            <option value="cash">Готовина</option>
                        </select>
                    </div>
                    <x-primary-button type="submit">Внеси плаќање</x-primary-button>
                </form>
            @endif
        </x-card>
    @endif

    <livewire:document-manager :documentable="$invoice" />
</div>
