<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Вработени — {{ $company->name }}</h1>
        @can('create', \App\Models\Employee::class)
            <a href="{{ route('employees.create', $company) }}" class="text-brand hover:underline text-sm">Нов вработен</a>
        @endcan
    </div>

    <label class="flex items-center gap-2 mb-4 text-sm text-gray-600">
        <input type="checkbox" wire:model.live="showTerminated" class="rounded border-gray-300">
        Прикажи ги и исклучените
    </label>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Име и презиме</th>
                <th class="py-1 px-3">ЕМБГ</th>
                <th class="py-1 px-3">Вработен од</th>
                <th class="py-1 px-3">Плата</th>
                <th class="py-1 px-3">Статус</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($employees as $employee)
                @php $salary = $employee->salaryOn($asOf); @endphp
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">
                        <a href="{{ route('employees.edit', [$company, $employee]) }}" class="text-brand hover:underline font-medium">{{ $employee->full_name }}</a>
                    </td>
                    <td class="py-1 px-3">{{ $employee->embg }}</td>
                    <td class="py-1 px-3">{{ $employee->employed_on?->format('d.m.Y') }}</td>
                    <td class="py-1 px-3">
                        @if ($salary)
                            {{ number_format($salary->amount, 0, ',', '.') }}
                            <span class="text-gray-400">{{ $salary->basis === 'gross' ? 'бруто' : 'нето' }}</span>
                            @if ($salary->effective_from->year < $year)
                                <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 px-2 text-xs text-gray-600">Запис од {{ $salary->effective_from->year }}</span>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-1 px-3">
                        @if ($employee->isActiveOn($asOf))
                            <span class="text-gray-500">Активен</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 text-xs text-gray-600">Исклучен</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 px-3 text-gray-500">Нема внесени вработени.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
