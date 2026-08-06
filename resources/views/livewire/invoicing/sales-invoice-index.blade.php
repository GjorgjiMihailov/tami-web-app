<div x-data="efakturaStatusRefresh()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Излезни фактури — {{ $company->name }}</h1>
        <div class="flex items-center gap-3">
            @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Освежи статуси</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
            @endif
            <a href="{{ route('sales-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Нова фактура</a>
        </div>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>

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
                <th class="py-2 px-4">Број</th>
                <th class="py-2 px-4">Купувач</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Статус</th>
                <th class="py-2 px-4">е-Фактура</th>
                <th class="py-2 px-4">Вкупно</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->invoice_number ? "{$invoice->fiscal_year}/{$invoice->invoice_number}" : '—' }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge></td>
                    <td class="py-2 px-4">
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
                    <td class="py-2 px-4">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline">Прегледај</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-4 px-4 text-gray-500">Нема издадено фактури.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

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
                        throw new Error(refreshBody?.message ?? refreshBody?.error ?? 'Освежувањето не успеа.');
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
