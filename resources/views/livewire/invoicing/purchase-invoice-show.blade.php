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
    @if ($editingNumber)
        <form wire:submit="changeNumber" class="flex items-center gap-2 mb-1">
            <span class="text-sm text-gray-500">Влезна фактура — {{ $invoice->partner->name }} #</span>
            <input type="text" wire:model="newNumber" class="border-gray-300 rounded-md text-sm w-40" autofocus>
            <button type="submit" class="text-sm text-brand font-semibold">Зачувај</button>
            <button type="button" wire:click="cancelEditingNumber" class="text-sm text-gray-500 hover:underline">Откажи</button>
        </form>
        @error('newNumber') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @else
        <h1 class="text-2xl font-bold text-gray-800 mb-1 flex items-center gap-2">
            Влезна фактура — {{ $invoice->partner->name }} #{{ $invoice->supplier_invoice_number }}
            @if (in_array($invoice->status, ['draft', 'confirmed']))
                <button type="button" wire:click="startEditingNumber" class="text-xs font-normal text-brand hover:underline" title="Смени број">Смени број</button>
            @endif
        </h1>
    @endif
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
    @error('delete') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

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
        @if (in_array($invoice->status, ['draft', 'confirmed']) && $invoice->payments->isEmpty())
            <button type="button" wire:click="delete" wire:confirm="Да ја избришам трајно оваа влезна фактура? {{ $invoice->status === 'confirmed' ? 'Книжењето и залихата ќе се сторнираат, а записот целосно ќе исчезне.' : '' }} Ова не може да се врати." class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-red-300 rounded-full font-semibold text-sm text-red-600 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2 transition sm:ml-auto">Избриши фактура</button>
        @endif
    </div>

    @if ($invoice->incomingEfakturaDocument)
        <div class="mt-4 mb-4 border-t border-b pt-4 pb-4" x-data="incomingEfakturaPdfFetch()">
            @if ($invoice->incomingEfakturaDocument->efaktura_pdf_path)
                <a href="{{ route('incoming-efaktura.pdf.download', [$company, $invoice->incomingEfakturaDocument]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-[#CF1C69] border border-transparent rounded-full font-semibold text-sm text-white shadow-sm hover:bg-[#B01658] focus:outline-none focus:ring-2 focus:ring-[#CF1C69] focus:ring-offset-2 transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                    Преземи е-Фактура
                </a>
            @elseif (auth()->user()->can('signEfaktura', $company))
                <button type="button" @click="run()" :disabled="busy" class="inline-flex items-center gap-2 px-4 py-2 bg-[#CF1C69] border border-transparent rounded-full font-semibold text-sm text-white shadow-sm hover:bg-[#B01658] focus:outline-none focus:ring-2 focus:ring-[#CF1C69] focus:ring-offset-2 transition disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                    <span x-show="!busy">Преземи е-Фактура</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
                <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
            @endif
        </div>

        @script
        <script>
            const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

            Alpine.data('incomingEfakturaPdfFetch', () => ({
                busy: false,
                error: '',
                statusText: '',
                async run() {
                    this.busy = true; this.error = '';
                    try {
                        this.statusText = 'Проверувам мост...';
                        const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                        if (!health || !health.ok) {
                            throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');
                        }

                        this.statusText = 'Читам токен...';
                        const certRes = await fetch('http://127.0.0.1:9847/certificate');
                        if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
                        const cert = await certRes.json();

                        if (cert.serialNumber !== @js(auth()->user()->efakturaSignerFor($company)?->serialNumber)) {
                            throw new Error('Приклучениот токен не одговара на регистрираниот за оваа компанија.');
                        }

                        this.statusText = 'Подготвувам текст за потпишување...';
                        const signingRes = await fetch(@js(route('incoming-efaktura.pdf.signing-input', [$company, $invoice->incomingEfakturaDocument])), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ certificateBase64: cert.certificateBase64 }),
                        });
                        if (!signingRes.ok) {
                            const errorBody = await signingRes.json().catch(() => null);
                            throw new Error(errorBody?.message ?? errorBody?.error ?? 'Серверот не можеше да го подготви текстот за потпишување.');
                        }
                        const { token, signingInput } = await signingRes.json();

                        this.statusText = 'Потпишувам (проверете го прозорецот на SafeNet)...';
                        const signRes = await fetch('http://127.0.0.1:9847/sign', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ data: toBase64Url(signingInput) }),
                        });
                        if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
                        const { signature } = await signRes.json();

                        this.statusText = 'Преземам е-Фактура...';
                        const storeRes = await fetch(@js(route('incoming-efaktura.pdf.store', [$company, $invoice->incomingEfakturaDocument])), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ token, signature }),
                        });
                        if (!storeRes.ok) {
                            const storeBody = await storeRes.json().catch(() => null);
                            const message = storeBody?.error === 'ujp_rejected'
                                ? `УЈП го одби барањето: ${storeBody.body}`
                                : (storeBody?.message ?? storeBody?.error ?? 'Преземањето не успеа.');
                            throw new Error(message);
                        }

                        window.location.reload();
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.busy = false;
                    }
                },
            }));
        </script>
        @endscript
    @endif

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
