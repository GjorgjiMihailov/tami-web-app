<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Плата — {{ $company->name }}</h1>
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Нова пресметка за {{ $year }}</label>
                <select wire:model="newMonth" class="rounded border-gray-300 text-sm">
                    <option value="">Избери месец</option>
                    @foreach (['Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'] as $i => $name)
                        <option value="{{ $i + 1 }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="createRun" class="rounded bg-brand px-3 py-2 text-sm text-white">Отвори</button>
        </div>
    </div>

    @error('newMonth') <p class="text-sm text-red-600 mb-4">{{ $message }}</p> @enderror

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Месец</th>
                    <th class="py-1 px-3">Вработени</th>
                    <th class="py-1 px-3 text-right">Бруто</th>
                    <th class="py-1 px-3 text-right">За исплата</th>
                    <th class="py-1 px-3">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($runs as $run)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">
                            <a href="{{ route('payroll-runs.show', [$company, $run]) }}" class="text-brand hover:underline font-medium">{{ $run->monthName() }}</a>
                        </td>
                        <td class="py-1 px-3">{{ $run->employees_count }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($run->employees->sum('gross'), 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($run->employees->sum('effective_net'), 2, ',', '.') }}</td>
                        <td class="py-1 px-3">
                            @if ($run->isDraft())
                                <span class="text-xs font-medium text-gray-600 bg-gray-100 rounded-full px-2 py-0.5">Нацрт</span>
                            @else
                                <span class="text-xs font-medium text-green-700 bg-green-100 rounded-full px-2 py-0.5">Потврдена</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-3 text-sm text-gray-400">Нема пресметки за {{ $year }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
