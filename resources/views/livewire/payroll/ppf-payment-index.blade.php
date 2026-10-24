<div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
        <h1 class="text-2xl font-bold text-gray-800">Исплати на физички лица — {{ $company->name }}</h1>
        @can('managePayroll', $company)
            <a href="{{ route('ppf-payments.create', $company) }}" class="rounded bg-brand px-3 py-2 text-sm text-white">Нова исплата</a>
        @endcan
    </div>
    <p class="text-sm text-gray-500 mb-4">
        Аконтативна пресметка за УЈП (ППФ) — договор на дело, авторски права, регрес и сл. За секој датум на исплата се прави посебен XML фајл.
    </p>

    <div class="mb-4 flex items-end gap-2">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Месец во {{ $year }}</label>
            <select wire:model.live="month" class="rounded border-gray-300 text-sm">
                <option value="">Цела година</option>
                @foreach (['Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'] as $i => $name)
                    <option value="{{ $i + 1 }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @forelse ($groups as $group)
        <x-card padding="p-0" class="overflow-hidden mb-4" wire:key="ppf-{{ $group['date'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-gray-50">
                <div>
                    <span class="font-semibold text-gray-800">{{ \App\Support\Format::date($group['date']) }}</span>
                    <span class="text-sm text-gray-500 ml-2">{{ $group['payments']->count() }} {{ $group['payments']->count() === 1 ? 'исплата' : 'исплати' }}, вкупно {{ number_format($group['total'], 0, ',', '.') }} ден.</span>
                    @if ($group['exported_at'])
                        <span class="text-xs text-green-700 bg-green-100 rounded-full px-2 py-0.5 ml-2">Симнат {{ \App\Support\Format::date($group['exported_at']) }}</span>
                    @endif
                </div>
                @if ($canExport)
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-gray-500" title="periodfrom и periodto во фајлот; основно е денот на исплатата, но се поднесува и подоцна">
                            Датум на пресметка
                            <input type="date" wire:model.live="periods.{{ $group['date'] }}" value="{{ $group['period'] }}"
                                   class="ml-1 rounded border-gray-300 text-sm py-1">
                        </label>
                        @if ($group['check']->passes())
                            <a href="{{ route('ppf-payments.export', [$company, $group['date']]) }}{{ $group['period'] !== $group['date'] ? '?period='.$group['period'] : '' }}" class="rounded bg-brand px-3 py-1.5 text-sm text-white">Преземи ППФ XML</a>
                        @else
                            <span class="text-sm text-gray-400">XML не може да се преземе — види ги грешките</span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($group['check']->errors !== [] || $group['check']->warnings !== [])
                <div class="px-3 py-2 text-sm space-y-1">
                    @foreach ($group['check']->errors as $error)
                        <div class="text-red-700">⛔ {{ $error }}</div>
                    @endforeach
                    @foreach ($group['check']->warnings as $warning)
                        <div class="text-amber-700">⚠ {{ $warning }}</div>
                    @endforeach
                </div>
            @endif

            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-1 px-3">Примател</th>
                        <th class="py-1 px-3">Вид на приход</th>
                        <th class="py-1 px-3 text-right">Бруто (ден.)</th>
                        <th class="py-1 px-3 w-32"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($group['payments'] as $payment)
                        <tr class="text-sm hover:bg-orange-50" wire:key="ppf-payment-{{ $payment->id }}">
                            <td class="py-1 px-3">{{ $payment->payee->fullName() }}</td>
                            <td class="py-1 px-3">
                                <span class="font-medium">{{ $payment->type_income_code }}{{ $payment->subtype_income_code ? ' / '.$payment->subtype_income_code : '' }}</span>
                                <span class="text-gray-500">{{ \App\Support\Ppf\PpfCodes::subtypeName((string) $payment->subtype_income_code) ?? \App\Support\Ppf\PpfCodes::typeName($payment->type_income_code) }}</span>
                            </td>
                            <td class="py-1 px-3 text-right">{{ number_format($payment->gross_income, 0, ',', '.') }}</td>
                            <td class="py-1 px-3 text-right">
                                @can('managePayroll', $company)
                                    <a href="{{ route('ppf-payments.edit', [$company, $payment]) }}" class="text-brand hover:underline">Уреди</a>
                                    <button type="button" wire:click="deletePayment({{ $payment->id }})"
                                            wire:confirm="Да се избрише оваа исплата?"
                                            class="text-red-600 hover:underline ml-2">Избриши</button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @empty
        <x-card>
            <p class="text-sm text-gray-400">Нема исплати за {{ $month === '' ? $year : 'избраниот месец' }}.</p>
        </x-card>
    @endforelse
</div>
