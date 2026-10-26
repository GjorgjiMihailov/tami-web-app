<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Исплати на физички лица — {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">
        Аконтативни пресметки за УЈП (ППФ) — договор на дело, авторски права, регрес и сл. Една пресметка е еден XML фајл; во ист ден може да имате повеќе пресметки.
    </p>

    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Месец во {{ $year }}</label>
            <select wire:model.live="month" class="rounded border-gray-300 text-sm">
                <option value="">Цела година</option>
                @foreach (['Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'] as $i => $name)
                    <option value="{{ $i + 1 }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        @can('managePayroll', $company)
            <form wire:submit="createCalculation" class="flex items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Датум на нова пресметка</label>
                    <input type="date" wire:model="newDate" class="rounded border-gray-300 text-sm">
                </div>
                <button type="submit" class="rounded bg-brand px-3 py-2 text-sm text-white">Нова пресметка</button>
            </form>
        @endcan
    </div>
    @error('newDate') <p class="text-sm text-red-600 mb-4">{{ $message }}</p> @enderror

    @forelse ($calculations as $row)
        @php($calculation = $row['model'])
        <x-card padding="p-0" class="overflow-hidden mb-4" wire:key="ppf-calc-{{ $calculation->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-gray-50">
                @if ($editingId === $calculation->id)
                    <form wire:submit="updateCalculation" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label class="block text-xs text-gray-500">Број</label>
                            <input type="text" wire:model="editNumber" class="w-20 rounded border-gray-300 text-sm py-1">
                            @error('editNumber') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Датум на пресметка</label>
                            <input type="date" wire:model="editDate" class="rounded border-gray-300 text-sm py-1">
                            @error('editDate') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit" class="rounded bg-brand px-3 py-1.5 text-sm text-white">Зачувај</button>
                        <button type="button" wire:click="cancelEditing" class="text-sm text-gray-600 hover:underline">Откажи</button>
                    </form>
                @else
                    <div>
                        <span class="font-semibold text-gray-800">Пресметка бр. {{ $calculation->number }}</span>
                        <span class="text-sm text-gray-600 ml-2">од {{ \App\Support\Format::date($calculation->period_date) }}</span>
                        <span class="text-sm text-gray-500 ml-2">{{ $calculation->payments->count() }} {{ $calculation->payments->count() === 1 ? 'исплата' : 'исплати' }}, вкупно {{ number_format($row['total'], 0, ',', '.') }} ден.</span>
                        @if ($calculation->exported_at)
                            <span class="text-xs text-green-700 bg-green-100 rounded-full px-2 py-0.5 ml-2">Симната {{ \App\Support\Format::date($calculation->exported_at) }}</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @can('managePayroll', $company)
                            <a href="{{ route('ppf-payments.create', [$company, $calculation]) }}" class="text-brand hover:underline">Додај исплата</a>
                            <button type="button" wire:click="startEditing({{ $calculation->id }})" class="text-brand hover:underline">Број и датум</button>
                            <button type="button" wire:click="deleteCalculation({{ $calculation->id }})"
                                    wire:confirm="Да се избрише пресметката бр. {{ $calculation->number }} заедно со сите нејзини исплати?"
                                    class="text-red-600 hover:underline">Избриши</button>
                        @endcan
                        @if ($canExport)
                            @if ($row['check']->passes())
                                <a href="{{ route('ppf-payments.export', [$company, $calculation]) }}" class="rounded bg-brand px-3 py-1.5 text-white">Преземи ППФ XML</a>
                            @else
                                <span class="text-gray-400">XML не може да се преземе — види ги грешките</span>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            @if ($row['check']->errors !== [] || $row['check']->warnings !== [])
                <div class="px-3 py-2 text-sm space-y-1">
                    @foreach ($row['check']->errors as $error)
                        <div class="text-red-700">⛔ {{ $error }}</div>
                    @endforeach
                    @foreach ($row['check']->warnings as $warning)
                        <div class="text-amber-700">⚠ {{ $warning }}</div>
                    @endforeach
                </div>
            @endif

            @if ($calculation->payments->isNotEmpty())
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="text-left text-sm text-gray-500">
                            <th class="py-1 px-3">Примател</th>
                            <th class="py-1 px-3">Датум на исплата</th>
                            <th class="py-1 px-3">Вид на приход</th>
                            <th class="py-1 px-3 text-right">Бруто (ден.)</th>
                            <th class="py-1 px-3 w-32"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($calculation->payments as $payment)
                            <tr class="text-sm hover:bg-orange-50" wire:key="ppf-payment-{{ $payment->id }}">
                                <td class="py-1 px-3">{{ $payment->payee->fullName() }}</td>
                                <td class="py-1 px-3">{{ \App\Support\Format::date($payment->payment_date) }}</td>
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
            @endif
        </x-card>
    @empty
        <x-card>
            <p class="text-sm text-gray-400">Нема пресметки за {{ $month === '' ? $year : 'избраниот месец' }}.</p>
        </x-card>
    @endforelse
</div>
