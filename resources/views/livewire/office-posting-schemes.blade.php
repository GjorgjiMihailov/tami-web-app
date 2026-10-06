<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Шеми за книжење — мој предлог</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Предлогот е комплетот шеми што ќе го добива секоја нова фирма што ја создавате. Постојните фирми не се менуваат.
        Како се прави: отворете „Шеми за книжење“ во некоја ваша фирма, дотерајте ја шемата, пробајте ја со „Пробај“ и притиснете „Зачувај и постави како мој предлог“.
    </p>

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Документ</th>
                    <th class="py-1 px-3">Нова фирма добива</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($entries as $entry)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 font-medium">{{ $entry['type']->label() }}</td>
                        <td class="py-1 px-3">{{ $entry['mine'] ? 'Личен предлог' : 'Стандарден' }}</td>
                        <td class="py-1 px-3 text-right">
                            @if ($entry['mine'])
                                <button type="button" wire:click="forgetSet('{{ $entry['type']->value }}')" wire:confirm="Да се врати на стандарден предлог за „{{ $entry['type']->label() }}“?" class="text-red-600 hover:underline">Врати на стандарден</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
