@php
    $checkable = $rows->filter(fn ($row) => $row['signer'] !== null);
@endphp
<div x-data="checkAllIncoming(@js($checkable->map(fn ($row) => ['id' => $row['company']->id, 'name' => $row['company']->name, 'serial' => $row['signer']->serialNumber])->values()))">
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Влезни е-Фактури — сите клиенти</h1>
    <p class="text-sm text-gray-500 mb-4">
        Проверува за нови е-Фактури од УЈП за сите фирми за кои си овластен. Токенот се отклучува еднаш —
        PIN се внесува само на првото потпишување. Прифаќањето и одбивањето се прави на влезните фактури на секоја фирма.
    </p>

    @if ($checkable->isEmpty())
        <p class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
            Немаш регистриран токен и е-УЈП ID.
            <a href="{{ route('profile') }}" wire:navigate class="font-semibold underline">Регистрирај ги во профилот</a>.
        </p>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <button type="button" @click="runAll()" :disabled="busy"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-brand border border-transparent rounded-full font-semibold text-sm text-white shadow-sm hover:bg-brand-dark disabled:opacity-50">
                <span x-show="!busy">Провери за сите клиенти ({{ $checkable->count() }})</span>
                <span x-show="busy" x-text="progress"></span>
            </button>
            <span class="text-sm text-gray-500" x-show="doneText" x-text="doneText" x-cloak></span>
        </div>
    @endif

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Фирма</th>
                    <th class="py-1 px-3">Последна проверка</th>
                    <th class="py-1 px-3 text-right">Неодлучени</th>
                    <th class="py-1 px-3">Проверка сега</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    @php $company = $row['company']; @endphp
                    <tr class="text-sm hover:bg-orange-50" wire:key="incoming-{{ $company->id }}">
                        <td class="py-1 px-3">{{ $company->name }}</td>
                        <td class="py-1 px-3 whitespace-nowrap">{{ $company->efaktura_purchase_last_checked_at ? \App\Support\Format::date($company->efaktura_purchase_last_checked_at) : 'никогаш' }}</td>
                        <td class="py-1 px-3 text-right">
                            @if ($row['pending'] > 0)
                                <x-badge status="pending">{{ $row['pending'] }}</x-badge>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="py-1 px-3">
                            @if ($row['signer'])
                                <span x-text="results[{{ $company->id }}] ?? '—'" :class="(results[{{ $company->id }}] ?? '').startsWith('Грешка') ? 'text-red-600' : 'text-gray-700'"></span>
                            @else
                                <span class="text-gray-500">Немаш токен</span>
                            @endif
                        </td>
                        <td class="py-1 px-3 whitespace-nowrap">
                            <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate class="text-brand hover:underline">Отвори влезни фактури</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-3 text-gray-500">Нема фирми со материјално работење.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    @script
    <script>
        const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

        // Патеките се составуваат од рутите со фирма 0, па 0 се заменува со вистинската.
        const urls = {
            idsInput: @js(route('incoming-efaktura.discover.ids.signing-input', 0)),
            ids: @js(route('incoming-efaktura.discover.ids', 0)),
            payloadInput: @js(route('incoming-efaktura.discover.payload.signing-input', 0)),
            payload: @js(route('incoming-efaktura.discover.payload', 0)),
            statusInput: @js(route('incoming-efaktura.discover.status.signing-input', 0)),
            status: @js(route('incoming-efaktura.discover.status', 0)),
        };
        const forCompany = (url, id) => url.replace('/companies/0/', `/companies/${id}/`);

        const signViaBridge = async (signingInputUrl, requestBody) => {
            const signingRes = await fetch(signingInputUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
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

        const postJson = async (url, body) => {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
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

        const readCertificate = async (expectedSerial) => {
            const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
            if (!health || !health.ok) throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');

            const certRes = await fetch('http://127.0.0.1:9847/certificate');
            if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
            const cert = await certRes.json();
            if (cert.serialNumber !== expectedSerial) throw new Error('Приклучениот токен не е токенот регистриран во твојот профил.');

            return cert;
        };

        // Истите три потпишани чекори како „Провери за е-Фактури“ на влезните фактури.
        const checkOne = async (company) => {
            const cert = await readCertificate(company.serial);

            const idsSigned = await signViaBridge(forCompany(urls.idsInput, company.id), { certificateBase64: cert.certificateBase64 });
            const { newEuids, dateFrom, dateTo } = await postJson(forCompany(urls.ids, company.id), { token: idsSigned.token, signature: idsSigned.signature });

            if (newEuids.length > 0) {
                const payloadSigned = await signViaBridge(forCompany(urls.payloadInput, company.id), { certificateBase64: cert.certificateBase64, euids: newEuids });
                await postJson(forCompany(urls.payload, company.id), { token: payloadSigned.token, signature: payloadSigned.signature });
            }

            const statusSigned = await signViaBridge(forCompany(urls.statusInput, company.id), { certificateBase64: cert.certificateBase64, dateFrom, dateTo });
            await postJson(forCompany(urls.status, company.id), { token: statusSigned.token, signature: statusSigned.signature });

            return newEuids.length;
        };

        Alpine.data('checkAllIncoming', (companies) => ({
            companies,
            busy: false,
            progress: '',
            doneText: '',
            results: {},
            async runAll() {
                this.busy = true; this.doneText = ''; this.results = {};
                let found = 0; let failed = 0;

                for (const [index, company] of this.companies.entries()) {
                    this.progress = `Проверувам ${index + 1} од ${this.companies.length}: ${company.name}...`;
                    try {
                        const count = await checkOne(company);
                        this.results[company.id] = count > 0 ? `Пронајдени ${count} нови` : 'Нема нови';
                        found += count;
                    } catch (e) {
                        // Грешката на една фирма (на пример без овластување во е-УЈП) не ги запира другите.
                        this.results[company.id] = `Грешка: ${e.message}`;
                        failed++;
                    }
                }

                this.busy = false;
                this.doneText = `Готово. Нови е-Фактури: ${found}.` + (failed > 0 ? ` Фирми со грешка: ${failed}.` : '');
                $wire.$refresh();
            },
        }));
    </script>
    @endscript
</div>
