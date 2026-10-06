<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Шеми за книжење — {{ $company->name }}</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Шемата одредува на кои конта се книжи секој вид документ. Промената важи само за нови документи — веќе книжените налози остануваат како што се.
    </p>

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Документ</th>
                    <th class="py-1 px-3">Редови</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($schemes as $entry)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 font-medium">{{ $entry['type']->label() }}</td>
                        <td class="py-1 px-3">{{ $entry['scheme']->rows_count }}</td>
                        <td class="py-1 px-3 text-right">
                            <a href="{{ route('accounting.posting-schemes.edit', [$company, $entry['type']->value]) }}" class="text-brand hover:underline">Отвори</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
