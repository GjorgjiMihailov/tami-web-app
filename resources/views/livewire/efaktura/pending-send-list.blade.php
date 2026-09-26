<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">е-Фактури што чекаат праќање</h1>
    <p class="text-sm text-gray-500 mb-4">
        Од апликацијата може да се прати само фактура на фирма со свој токен, и тоа од компјутерот каде е приклучен токенот на таа фирма. Фирмите во режим „канцеларија“ засега не може да праќаат од апликацијата.
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
                            @if (auth()->user()->can('signEfaktura', $company))
                                <x-badge status="active">Можеш да потпишеш</x-badge>
                            @else
                                <x-badge status="pending">Немаш регистриран токен</x-badge>
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
