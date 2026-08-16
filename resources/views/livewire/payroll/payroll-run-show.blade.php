<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $run->monthName() }} {{ $run->year }} — {{ $company->name }}</h1>
            <p class="text-sm text-gray-500">Фонд на часови: {{ $run->month_hours }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('payroll.recap-pdf', [$company, $run]) }}" class="text-brand hover:underline text-sm">Рекапитулар (PDF)</a>
            @if ($run->isDraft())
                <button wire:click="confirm" class="rounded bg-brand px-3 py-2 text-sm text-white">Потврди</button>
            @else
                <span class="text-xs font-medium text-green-700 bg-green-100 rounded-full px-2 py-0.5">Потврдена</span>
                <button wire:click="returnToDraft" class="rounded border border-gray-300 px-3 py-2 text-sm">Врати во нацрт</button>
            @endif
        </div>
    </div>

    @error('lineKind') <p class="text-sm text-red-600 mb-4">{{ $message }}</p> @enderror

    <x-card padding="p-0" class="overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Вработен</th>
                    <th class="py-1 px-3 text-right">Часови</th>
                    <th class="py-1 px-3 text-right">Бруто</th>
                    <th class="py-1 px-3 text-right">Придонеси</th>
                    <th class="py-1 px-3 text-right">Данок</th>
                    <th class="py-1 px-3 text-right">Задршки</th>
                    <th class="py-1 px-3 text-right">За исплата</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    @php $hours = $row->lines->sum('hours'); @endphp
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">
                            <button wire:click="selectEmployee({{ $row->id }})" class="text-brand hover:underline font-medium">{{ $row->employee->full_name }}</button>
                        </td>
                        <td class="py-1 px-3 text-right">
                            {{ $hours }}
                            @if ($hours != $run->month_hours)
                                <span class="text-xs text-amber-700 bg-amber-100 rounded-full px-2 py-0.5">не го затвора фондот</span>
                            @endif
                        </td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->gross, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->contributions, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->tax, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->deductions_total, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right font-medium">{{ number_format($row->effective_net, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">
                            <a href="{{ route('payroll.payslip-pdf', [$company, $run, $row]) }}" class="text-brand hover:underline text-xs">Исплатна листа</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 text-sm font-medium">
                <tr>
                    <td class="py-1 px-3" colspan="2">Вкупно</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('gross'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('contributions'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('tax'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('deductions_total'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('effective_net'), 2, ',', '.') }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </x-card>

    @if ($selected)
        <x-card>
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Ставки — {{ $selected->employee->full_name }}</h2>

            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-1 px-3">Ставка</th>
                        <th class="py-1 px-3 text-right">Часови</th>
                        <th class="py-1 px-3 text-right">%</th>
                        <th class="py-1 px-3 text-right">Износ</th>
                        <th class="py-1 px-3">Товар</th>
                        <th class="py-1 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($selected->lines as $line)
                        <tr class="text-sm hover:bg-orange-50">
                            <td class="py-1 px-3">{{ $line->description }}</td>
                            <td class="py-1 px-3 text-right">{{ $line->hours }}</td>
                            <td class="py-1 px-3 text-right">{{ $line->percent ? number_format($line->percent, 0) : '' }}</td>
                            <td class="py-1 px-3 text-right">{{ number_format($line->amount, 2, ',', '.') }}</td>
                            <td class="py-1 px-3">{{ $line->borne_by === 'fzo' ? 'ФЗО' : 'Работодавач' }}</td>
                            <td class="py-1 px-3 text-right">
                                @if (! $line->is_automatic && $run->isDraft())
                                    <button wire:click="deleteLine({{ $line->id }})" class="text-red-600 hover:underline text-xs">Избриши</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($run->isDraft())
                <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">Ставка</label>
                        <select wire:model.live="lineCode" class="w-full rounded border-gray-300 text-sm">
                            @foreach ($offered as $code => $type)
                                <option value="{{ $code }}">{{ $type['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Вид</label>
                        <select wire:model.live="lineKind" class="w-full rounded border-gray-300 text-sm">
                            <option value="hours">Часови</option>
                            <option value="amount">Износ</option>
                            <option value="deduction">Задршка</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Часови</label>
                        <input type="number" step="1" wire:model="lineHours" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Процент</label>
                        <input type="number" step="0.01" wire:model="linePercent" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Износ</label>
                        <input type="number" step="0.01" wire:model="lineAmount" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div class="md:col-span-5">
                        <label class="block text-xs text-gray-500 mb-1">Опис</label>
                        <input type="text" wire:model="lineDescription" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <button wire:click="saveLine" class="w-full rounded bg-brand px-3 py-2 text-sm text-white">Додади</button>
                    </div>
                </div>

                @error('lineHours') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                @error('lineAmount') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                @error('lineDescription') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
            @endif
        </x-card>
    @endif
</div>
