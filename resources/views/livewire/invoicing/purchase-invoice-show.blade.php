<div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
    {{-- Лева страна: влезните фактури од работната година --}}
    <x-card padding="p-0" class="hidden lg:block overflow-hidden self-start">
        <div class="p-3 border-b border-sand flex items-center justify-between gap-2">
            <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate class="text-sm font-semibold text-gray-700 hover:underline">← Сите влезни фактури</a>
            <a href="{{ route('purchase-invoices.create', $company) }}" wire:navigate
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand text-white text-xl leading-none" title="Нова влезна фактура" aria-label="Нова влезна фактура">+</a>
        </div>
        <ul class="divide-y divide-gray-100 max-h-[75vh] overflow-y-auto">
            @foreach ($sidebar as $row)
                <li wire:key="side-{{ $row->id }}">
                    <a href="{{ route('purchase-invoices.show', [$company, $row]) }}" wire:navigate
                       class="block px-4 py-2 hover:bg-orange-50 {{ $row->id === $invoice->id ? 'bg-orange-50' : '' }}">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-sm truncate">{{ $row->partner->name }}</span>
                            <span class="text-sm whitespace-nowrap">{{ \App\Support\Format::money($row->grandTotal()) }}</span>
                        </span>
                        <span class="flex items-center justify-between text-xs text-gray-500">
                            <span>{{ $row->supplier_invoice_number }} · {{ \App\Support\Format::date($row->invoice_date) }}</span>
                            <span class="{{ $row->status === 'confirmed' && $row->isOverdue() ? 'text-red-600' : '' }}">
                                @if ($row->status === 'confirmed')
                                    {{ $row->isOverdue() ? 'Доспеана пред '.$row->daysOverdue().' '.($row->daysOverdue() === 1 ? 'ден' : 'дена') : \App\Support\Format::paymentStatus($row->paymentStatus()) }}
                                @else
                                    {{ \App\Support\Format::invoiceStatus($row->status) }}
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-card>

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

    {{-- Што следува --}}
    @if ($invoice->status === 'draft')
        <p class="mb-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">Што следува? Прегледај ја фактурата и потврди ја — тогаш се книжи и (за артикли од залиха) влегува во залиха.</p>
    @elseif ($invoice->status === 'confirmed' && $invoice->isOverdue())
        <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Што следува? Фактурата е доспеана пред {{ $invoice->daysOverdue() }} {{ $invoice->daysOverdue() === 1 ? 'ден' : 'дена' }}. Евидентирај го плаќањето кога ќе го направиш.</p>
    @elseif ($invoice->status === 'confirmed' && $invoice->paymentStatus() !== 'paid')
        <p class="mb-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">Што следува? Евидентирај го плаќањето кога ќе го направиш — формата „Плаќања“ е подолу.</p>
    @endif

    <x-card class="mb-4">
        <dl class="grid gap-y-1 gap-x-6 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-gray-500">Датум на фактура</dt>
            <dd>{{ \App\Support\Format::date($invoice->invoice_date) }}</dd>
            <dt class="text-gray-500">Рок на доспевање</dt>
            <dd>{{ \App\Support\Format::date($invoice->due_date) }}</dd>
            @if ($invoice->order_number)
                <dt class="text-gray-500">Нарачка</dt>
                <dd>{{ $invoice->order_number }}</dd>
            @endif
            <dt class="text-gray-500">Добавувач</dt>
            <dd><a href="{{ route('partners.index', [$company, 'partner' => $invoice->partner_id]) }}" wire:navigate class="text-brand hover:underline">{{ $invoice->partner->name }}</a></dd>
        </dl>
    </x-card>

    <x-card class="mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Артикл/Сметка</th>
                    <th class="py-1">Кол.</th>
                    <th class="py-1">Ед. цена</th>
                    @if ($invoice->lines->contains(fn ($l) => $l->hasDiscount()))
                        <th class="py-1">Рабат %</th>
                    @endif
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
                        <td class="py-1">{{ \App\Support\Format::money($invoice->lines->contains(fn ($l) => $l->hasDiscount()) ? $line->originalUnitPrice() : $line->effectiveUnitPrice(), 'ден', $line->isGrossEntered() ? 4 : 2) }}</td>
                        @if ($invoice->lines->contains(fn ($l) => $l->hasDiscount()))
                            <td class="py-1">{{ $line->hasDiscount() ? \App\Support\Format::rate($line->discount_percent) : '' }}</td>
                        @endif
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

    <div class="flex flex-wrap items-center gap-2 mb-4">
        @if ($invoice->status === 'draft')
            <a href="{{ route('purchase-invoices.edit', [$company, $invoice]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">Измени</a>
            <button type="button" wire:click="confirm" class="inline-flex items-center gap-2 px-4 py-2 bg-brand border border-transparent rounded-full font-semibold text-sm text-white shadow-sm hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">Потврди</button>
        @endif
        @if ($invoice->status === 'confirmed' && $invoice->payments->isEmpty())
            <button type="button" wire:click="cancel" wire:confirm="Да ја откажам фактурата? Книжењето ќе се сторнира." class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-red-300 rounded-full font-semibold text-sm text-red-600 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2 transition sm:ml-auto">Откажи фактура</button>
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
</div>
