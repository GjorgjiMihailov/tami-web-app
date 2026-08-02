<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
        {{ $invoice->status === 'confirmed' ? "Фактура бр. {$invoice->fiscal_year}/{$invoice->invoice_number}" : 'Нацрт фактура' }}
    </h1>
    <p class="text-sm text-gray-500 mb-4 flex items-center gap-2">
        {{ $invoice->partner->name }}
        <x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge>
        @if ($invoice->status === 'confirmed')
            <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->paymentStatus()">
                {{ $invoice->isOverdue() ? 'Задоцнета' : \App\Support\Format::paymentStatus($invoice->paymentStatus()) }}
            </x-badge>
        @endif
    </p>

    @error('confirm') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @error('cancel') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

    <x-card class="mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Кол.</th>
                    <th class="py-1">Ед. цена</th>
                    <th class="py-1">ДДВ %</th>
                    <th class="py-1">Вкупно за ставка</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->quantity }}</td>
                        <td class="py-1">{{ \App\Support\Format::money($line->unit_price) }}</td>
                        <td class="py-1">{{ $line->vat_rate }}{{ $line->vat_treatment !== 'standard' ? ' ('.\App\Support\Format::vatTreatment($line->vat_treatment).')' : '' }}</td>
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
            <a href="{{ route('sales-invoices.edit', [$company, $invoice]) }}" class="text-brand hover:underline text-sm">Измени</a>
            <button type="button" wire:click="confirm" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Потврди</button>
        @endif
        @if ($invoice->status === 'confirmed')
            <a href="{{ route('sales-invoices.pdf', [$company, $invoice]) }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
            @if (! $invoice->sent_at)
                <button type="button" wire:click="markSent" class="text-brand hover:underline text-sm">Означи како испратена</button>
            @endif
            @if ($invoice->payments->isEmpty())
                <button type="button" wire:click="cancel" class="text-red-600 hover:underline text-sm">Откажи фактура</button>
            @endif
        @endif
    </div>

    @if ($invoice->status === 'confirmed' && auth()->user()->hasAnyRole(['admin', 'accountant']))
        <div class="mt-4 border-t pt-4" x-data="efakturaSend()">
            @if ($invoice->efaktura_status === 'sent')
                <x-badge status="active">Испратена до УЈП ({{ optional($invoice->efaktura_sent_at)->format('d.m.Y H:i') }})</x-badge>
            @elseif (! $company->hasEfakturaAccess() || $company->efaktura_credential_mode !== \App\Models\Company::EFAKTURA_MODE_OWN)
                <p class="text-xs text-gray-500">Регистрирај потпишувачки уред за оваа компанија (Профил на фирма) за да можеш да праќаш е-Фактура.</p>
            @else
                <button type="button" @click="run()" :disabled="busy" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Потпиши и испрати до УЈП</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
                @if ($invoice->efaktura_status === 'failed')
                    <p class="text-red-600 text-sm mt-2">Претходен обид не успеа: {{ Str::limit($invoice->efaktura_error, 200) }}</p>
                @endif
                <p x-show="error" x-text="error" class="text-red-600 text-sm mt-2"></p>
                <p x-show="success" class="text-green-700 text-sm mt-2">Фактурата е успешно испратена до УЈП.</p>
            @endif
        </div>

        @script
        <script>
            const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

            Alpine.data('efakturaSend', () => ({
                busy: false,
                error: '',
                success: false,
                statusText: '',
                async run() {
                    this.busy = true; this.error = ''; this.success = false;
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

                        if (cert.serialNumber !== @js($company->efaktura_token_serial_number)) {
                            throw new Error('Приклучениот токен не одговара на регистрираниот за оваа компанија.');
                        }

                        this.statusText = 'Подготвувам текст за потпишување...';
                        const signingRes = await fetch(@js(route('sales-invoices.efaktura.signing-input', [$company, $invoice])), {
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

                        this.statusText = 'Праќам до УЈП...';
                        const sendRes = await fetch(@js(route('sales-invoices.efaktura.send', [$company, $invoice])), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ token, signature }),
                        });
                        if (!sendRes.ok) {
                            const sendBody = await sendRes.json().catch(() => null);
                            const message = sendBody?.error === 'ujp_rejected'
                                ? `УЈП го одби барањето: ${sendBody.body}`
                                : (sendBody?.message ?? sendBody?.error ?? 'Праќањето не успеа.');
                            throw new Error(message);
                        }

                        this.success = true;
                        setTimeout(() => window.location.reload(), 1500);
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
