<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">е-Фактури што чекаат праќање</h1>
    <p class="text-sm text-gray-500 mb-4">
        Потврдени излезни фактури што сè уште не се пратени до УЈП. Праќањето се прави од самата
        фактура, на компјутерот каде е приклучен токенот на фирмата.
    </p>

    <x-card>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Клиент</th>
                    <th class="py-1">Фактура</th>
                    <th class="py-1">Датум</th>
                    <th class="py-1">Купувач</th>
                    <th class="py-1 text-right">Износ</th>
                    <th class="py-1">Токен</th>
                    <th class="py-1">Состојба</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    @php($company = $invoice->company)
                    <tr class="hover:bg-orange-50" wire:key="pending-{{ $invoice->id }}">
                        <td class="py-1">{{ $company->name }}</td>
                        <td class="py-1">{{ $invoice->formattedNumber() ?? '—' }}</td>
                        <td class="py-1">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                        <td class="py-1">{{ $invoice->partner?->name }}</td>
                        <td class="py-1 text-right">{{ \App\Support\Format::money($invoice->grandTotal(), 'ден') }}</td>
                        <td class="py-1">
                            @if ($company->efaktura_credential_mode !== \App\Models\Company::EFAKTURA_MODE_OWN)
                                <span class="text-gray-500">Токен на канцеларијата — праќањето не е поддржано</span>
                            @elseif ($company->hasEfakturaAccess())
                                <x-badge status="active">Запишан</x-badge>
                            @else
                                <x-badge status="pending">Нема запишан токен</x-badge>
                            @endif
                        </td>
                        <td class="py-1">
                            @if ($invoice->efaktura_status === 'failed')
                                <x-badge status="overdue">Неуспешен обид</x-badge>
                            @else
                                <span class="text-gray-500">Не е пратена</span>
                            @endif
                        </td>
                        <td class="py-1 text-right">
                            <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline">Отвори</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-3 text-gray-500">Нема фактури што чекаат праќање.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
