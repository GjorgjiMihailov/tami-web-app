<div x-data="efakturaStatusRefresh()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Излезни фактури — {{ $company->name }}</h1>
        <div class="flex items-center gap-3">
                @if (auth()->user()->can('signEfaktura', $company))
                    <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                        <span x-show="!busy">Освежи статуси</span>
                        <span x-show="busy" x-text="statusText"></span>
                    </button>
                @endif
                <a href="{{ route('sales-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">+ Нова фактура</a>
        </div>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>

    @if (! $hasInvoices)
        <x-card class="py-14">
            <div class="text-center">
                <h2 class="text-xl font-semibold text-gray-800">Време е да ви платат!</h2>
                <p class="mt-1 text-sm text-gray-500">Издавањето фактури и наплатата се полесни од кога било. Создадете ја првата фактура.</p>
                @can('create', \App\Models\SalesInvoice::class)
                    <a href="{{ route('sales-invoices.create', $company) }}" class="mt-6 inline-block">
                        <x-primary-button type="button">+ Нова фактура</x-primary-button>
                    </a>
                @endcan
            </div>

            <div class="mt-10 flex flex-wrap items-center justify-center gap-2 text-sm">
                @foreach (['Нацрт', 'Потврдена', 'Неплатена', 'Делумно платена', 'Платена'] as $step)
                    <span class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sky-800">{{ $step }}</span>
                    @unless ($loop->last) <span class="text-gray-400" aria-hidden="true">→</span> @endunless
                @endforeach
            </div>

            <ul class="mt-8 mx-auto max-w-xl space-y-2 text-sm text-gray-700">
                <li>✓ Направи фактура од профактура или од нула — со рабат по ставка и рок на плаќање.</li>
                <li>✓ Потврди ја за да добие број и да се книжи, па испрати ја до УЈП преку е-Фактура.</li>
                <li>✓ Евидентирај ги уплатите и следи што е доспеано.</li>
            </ul>
        </x-card>
    @else
        <div class="mb-4">
            <select wire:model.live="statusFilter" aria-label="Филтер" class="border-gray-300 rounded-md text-sm">
                <option value="">Сите фактури</option>
                <option value="draft">Нацрти</option>
                <option value="confirmed">Потврдени</option>
                <option value="unpaid">Неплатени</option>
                <option value="overdue">Доспеани</option>
                <option value="paid">Платени</option>
                <option value="cancelled">Откажани</option>
            </select>
        </div>

        <x-card padding="p-0" class="overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Датум</th>
                    <th class="py-1 px-3">Број</th>
                    <th class="py-1 px-3">Профактура</th>
                    <th class="py-1 px-3">Купувач</th>
                    <th class="py-1 px-3">Статус</th>
                    <th class="py-1 px-3">Рок</th>
                    <th class="py-1 px-3 text-right">Износ</th>
                    <th class="py-1 px-3 text-right">За наплата</th>
                    <th class="py-1 px-3">е-Фактура</th>
                    <th class="py-1 px-3">ПДФ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($invoices as $invoice)
                    @php $suffix = $invoice->currency === 'MKD' ? 'ден' : $invoice->currency; @endphp
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                        <td class="py-1 px-3 whitespace-nowrap">
                            <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline font-medium">{{ $invoice->formattedNumber() ?? 'Нацрт' }}</a>
                        </td>
                        <td class="py-1 px-3 whitespace-nowrap">{{ $invoice->order_number ?: '—' }}</td>
                        <td class="py-1 px-3">{{ $invoice->partner->name }}</td>
                        <td class="py-1 px-3 whitespace-nowrap">
                            @if ($invoice->status === 'confirmed')
                                <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->paymentStatus()">
                                    {{ $invoice->isOverdue() ? 'Доспеана пред '.$invoice->daysOverdue().' '.($invoice->daysOverdue() === 1 ? 'ден' : 'дена') : \App\Support\Format::paymentStatus($invoice->paymentStatus()) }}
                                </x-badge>
                            @else
                                <x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge>
                            @endif
                        </td>
                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($invoice->due_date) }}</td>
                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($invoice->grandTotal(), $suffix) }}</td>
                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ $invoice->status === 'confirmed' ? \App\Support\Format::money($invoice->balanceDue(), $suffix) : '—' }}</td>
                        <td class="py-1 px-3">
                            @if ($invoice->efaktura_ujp_status_name)
                                <x-badge :status="$invoice->isEfakturaAccepted() ? 'active' : 'pending'">{{ $invoice->efaktura_ujp_status_name }}</x-badge>
                            @elseif ($invoice->efaktura_status === 'sent')
                                <x-badge status="pending">Испратена</x-badge>
                            @elseif ($invoice->efaktura_status === 'failed')
                                <x-badge status="overdue">Не успеа</x-badge>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1 px-3">
                            @if ($invoice->efaktura_pdf_path)
                                <a href="{{ route('sales-invoices.efaktura.pdf.download', [$company, $invoice]) }}" class="text-brand hover:underline">Преземи ПДФ</a>
                            @elseif ($invoice->isEfakturaAccepted() && auth()->user()->can('signEfaktura', $company))
                                <div x-data="efakturaPdfFetch({{ $invoice->id }})">
                                    <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                        <span x-show="!busy">Преземи ПДФ</span>
                                        <span x-show="busy" x-text="statusText"></span>
                                    </button>
                                    <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                </div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-4 px-3 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        </x-card>
    @endif
    @script
    <script>
        const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        Alpine.data('efakturaStatusRefresh', () => ({
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
                    const signingRes = await fetch(@js(route('sales-invoices.efaktura.refresh-statuses.signing-input', $company)), {
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

                    this.statusText = 'Ги освежувам статусите...';
                    const refreshRes = await fetch(@js(route('sales-invoices.efaktura.refresh-statuses', $company)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ token, signature }),
                    });
                    if (!refreshRes.ok) {
                        const refreshBody = await refreshRes.json().catch(() => null);
                        const message = refreshBody?.error === 'ujp_rejected'
                            ? `УЈП го одби барањето: ${refreshBody.body}`
                            : (refreshBody?.message ?? refreshBody?.error ?? 'Освежувањето не успеа.');
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

        Alpine.data('efakturaPdfFetch', (invoiceId) => ({
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
                    const signingRes = await fetch(`/companies/{{ $company->id }}/sales-invoices/${invoiceId}/efaktura/pdf/signing-input`, {
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

                    this.statusText = 'Преземам ПДФ...';
                    const storeRes = await fetch(`/companies/{{ $company->id }}/sales-invoices/${invoiceId}/efaktura/pdf`, {
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
</div>
