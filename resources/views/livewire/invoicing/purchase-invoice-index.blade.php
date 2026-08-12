<div x-data="incomingEfakturaDiscover()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Влезни фактури — {{ $company->name }}</h1>
        <div class="flex items-center gap-3">
            @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Провери за е-Фактури</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
            @endif
            <a href="{{ route('purchase-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Нова влезна фактура</a>
        </div>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>
    <p x-show="result" x-text="result" class="text-green-700 text-sm mb-3"></p>

    @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
        <p class="text-sm text-gray-500 mb-4">
            Последна проверка за е-Фактури:
            {{ $company->efaktura_purchase_last_checked_at ? \App\Support\Format::date($company->efaktura_purchase_last_checked_at) : 'никогаш' }}
        </p>
    @endif

    @if ($pendingDocuments->isNotEmpty())
        <h2 class="text-lg font-semibold text-gray-700 mb-2">Неодлучени е-Фактури</h2>
        <x-card padding="p-0" class="overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Добавувач</th>
                    <th class="py-1 px-3">Датум</th>
                    <th class="py-1 px-3">Износ</th>
                    <th class="py-1 px-3">Статус кај УЈП</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($pendingDocuments as $document)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">{{ $document->seller_name }} <span class="text-gray-400">({{ $document->seller_tax_id }})</span></td>
                        <td class="py-1 px-3">{{ $document->doc_date ? \App\Support\Format::date($document->doc_date) : '—' }}</td>
                        <td class="py-1 px-3">{{ $document->total_amount !== null ? \App\Support\Format::money($document->total_amount) : '—' }}</td>
                        <td class="py-1 px-3">
                            @if ($document->status_name)
                                <x-badge status="pending">{{ $document->status_name }}</x-badge>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1 px-3">
                            @if ($document->decision === null)
                                @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                                    <div x-data="incomingEfakturaAccept({{ $document->id }})" class="inline-block mr-2 align-top">
                                        <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                            <span x-show="!busy">Прифати</span>
                                            <span x-show="busy" x-text="statusText"></span>
                                        </button>
                                        <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                    </div>
                                    <div x-data="incomingEfakturaReject({{ $document->id }})" class="inline-block align-top">
                                        <button type="button" @click="open = !open" class="text-red-600 hover:underline">Одбиј</button>
                                        <div x-show="open" class="mt-2 p-2 border border-gray-200 rounded-md bg-gray-50 w-64">
                                            <select x-model="reasonCode" class="border-gray-300 rounded-md text-sm w-full mb-1">
                                                <option value="">Избери причина...</option>
                                                @foreach (\App\Models\IncomingEfakturaDocument::REJECT_REASONS as $code => $label)
                                                    <option value="{{ $code }}">{{ $code }} — {{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <input x-show="reasonCode === @js(\App\Models\IncomingEfakturaDocument::REJECT_REASON_OTHER)" x-model="comment" type="text" placeholder="Причина (слободен текст)" class="border-gray-300 rounded-md text-sm w-full mb-1">
                                            <button type="button" @click="run()" :disabled="busy || !reasonCode" class="text-red-600 text-sm disabled:opacity-50">
                                                <span x-show="!busy">Потврди одбивање</span>
                                                <span x-show="busy" x-text="statusText"></span>
                                            </button>
                                            <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            @elseif ($document->decision === \App\Models\IncomingEfakturaDocument::DECISION_REJECTED)
                                <x-badge status="overdue">Одбиена</x-badge>
                                <span class="text-gray-400 text-xs block">{{ $document->reject_reason_code }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-card>
    @endif

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
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Бр. кај добавувач</th>
                <th class="py-1 px-3">Добавувач</th>
                <th class="py-1 px-3">Датум</th>
                <th class="py-1 px-3">Статус</th>
                <th class="py-1 px-3">Вкупно</th>
                <th class="py-1 px-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">{{ $invoice->supplier_invoice_number }}</td>
                    <td class="py-1 px-3">{{ $invoice->partner->name }}</td>
                    <td class="py-1 px-3">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                    <td class="py-1 px-3"><x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge></td>
                    <td class="py-1 px-3">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                    <td class="py-1 px-3">
                        <a href="{{ route('purchase-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline mr-2">Прегледај</a>
                        @if ($invoice->incomingEfakturaDocument)
                            @if ($invoice->incomingEfakturaDocument->efaktura_pdf_path)
                                <a href="{{ route('incoming-efaktura.pdf.download', [$company, $invoice->incomingEfakturaDocument]) }}" class="text-brand hover:underline">Преземи ПДФ</a>
                            @elseif ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                                <div x-data="incomingEfakturaPdfFetch({{ $invoice->incomingEfakturaDocument->id }})" class="inline-block">
                                    <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                        <span x-show="!busy">Преземи ПДФ</span>
                                        <span x-show="busy" x-text="statusText"></span>
                                    </button>
                                    <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                </div>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-3 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

    @script
    <script>
        const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        // Shared "sign this JSON body with the local bridge" step used by every flow below:
        // POST signingInputUrl -> get {token, signingInput, ...extra}, POST the signingInput to
        // the bridge's /sign, return {token, signature, ...extra}. Declared as `const ... = (...) =>`,
        // not `function name(){}` — Livewire's script block evaluates as one expression, so a plain
        // function declaration is invisible outside itself (a real bug already hit and fixed in
        // Phase 8b-ii's sales-invoice-index.blade.php).
        const signViaBridge = async (signingInputUrl, requestBody) => {
            const signingRes = await fetch(signingInputUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(requestBody),
            });
            if (!signingRes.ok) {
                const errorBody = await signingRes.json().catch(() => null);
                throw new Error(errorBody?.message ?? errorBody?.error ?? 'Серверот не можеше да го подготви текстот за потпишување.');
            }
            const signingJson = await signingRes.json();

            const signRes = await fetch('http://127.0.0.1:9847/sign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ data: toBase64Url(signingJson.signingInput) }),
            });
            if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
            const { signature } = await signRes.json();

            return { ...signingJson, signature };
        };

        const readTokenCertificate = async (expectedSerial) => {
            const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
            if (!health || !health.ok) throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');

            const certRes = await fetch('http://127.0.0.1:9847/certificate');
            if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
            const cert = await certRes.json();
            if (cert.serialNumber !== expectedSerial) throw new Error('Приклучениот токен не одговара на регистрираниот за оваа компанија.');

            return cert;
        };

        const postJson = async (url, body) => {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                const errorBody = await res.json().catch(() => null);
                const message = errorBody?.error === 'ujp_rejected'
                    ? `УЈП го одби барањето: ${errorBody.body}`
                    : (errorBody?.message ?? errorBody?.error ?? 'Барањето не успеа.');
                throw new Error(message);
            }

            return res.json();
        };

        Alpine.data('incomingEfakturaDiscover', () => ({
            busy: false,
            error: '',
            result: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = ''; this.result = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Барам список на нови фактури...';
                    const idsSigned = await signViaBridge(
                        @js(route('incoming-efaktura.discover.ids.signing-input', $company)),
                        { certificateBase64: cert.certificateBase64 }
                    );
                    const { newEuids, dateFrom, dateTo } = await postJson(
                        @js(route('incoming-efaktura.discover.ids', $company)),
                        { token: idsSigned.token, signature: idsSigned.signature }
                    );

                    if (newEuids.length > 0) {
                        this.statusText = `Преземам ${newEuids.length} нови фактури...`;
                        const payloadSigned = await signViaBridge(
                            @js(route('incoming-efaktura.discover.payload.signing-input', $company)),
                            { certificateBase64: cert.certificateBase64, euids: newEuids }
                        );
                        await postJson(
                            @js(route('incoming-efaktura.discover.payload', $company)),
                            { token: payloadSigned.token, signature: payloadSigned.signature }
                        );
                    }

                    this.statusText = 'Ги освежувам статусите...';
                    const statusSigned = await signViaBridge(
                        @js(route('incoming-efaktura.discover.status.signing-input', $company)),
                        { certificateBase64: cert.certificateBase64, dateFrom, dateTo }
                    );
                    await postJson(
                        @js(route('incoming-efaktura.discover.status', $company)),
                        { token: statusSigned.token, signature: statusSigned.signature }
                    );

                    this.result = `Пронајдени ${newEuids.length} нови фактури.`;
                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaAccept', (documentId) => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Прифаќам (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/accept/signing-input`,
                        { certificateBase64: cert.certificateBase64 }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/accept`,
                        { token: signed.token, signature: signed.signature }
                    );

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaReject', (documentId) => ({
            open: false,
            busy: false,
            error: '',
            statusText: '',
            reasonCode: '',
            comment: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Одбивам (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/reject/signing-input`,
                        { certificateBase64: cert.certificateBase64, reasonCode: this.reasonCode, comment: this.comment || null }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/reject`,
                        { token: signed.token, signature: signed.signature }
                    );

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaPdfFetch', (documentId) => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Преземам ПДФ (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/pdf/signing-input`,
                        { certificateBase64: cert.certificateBase64 }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/pdf`,
                        { token: signed.token, signature: signed.signature }
                    );

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
