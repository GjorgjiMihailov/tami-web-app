<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Банкарски документи — {{ $company->name }}</h1>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Прикачи извод</h2>
        <form wire:submit="upload" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[12rem]">
                <x-input-label for="bank" value="Банка" />
                <x-text-input id="bank" wire:model="bank" class="w-full" />
                @error('bank') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-48">
                <x-input-label for="account" value="Сметка" />
                <x-text-input id="account" wire:model="account" class="w-full" />
                @error('account') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-36">
                <x-input-label for="kind" value="Вид" />
                <select id="kind" wire:model="kind" class="border-gray-300 rounded-md text-sm w-full">
                    @foreach ($kinds as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('kind') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-24">
                <x-input-label for="number" value="Број" />
                <x-text-input id="number" wire:model="number" class="w-full text-right" />
                @error('number') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-40">
                <x-input-label for="statementDate" value="Датум" />
                <x-text-input id="statementDate" type="date" wire:model="statementDate" class="w-full" />
                @error('statementDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="newFile" value="Датотека" />
                <input type="file" id="newFile" wire:model="newFile" class="text-sm">
                @error('newFile') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-primary-button type="submit">Прикачи</x-primary-button>
        </form>
    </x-card>

    @forelse ($groups as $group)
        <x-card class="mb-4">
            <h2 class="font-semibold text-gray-700 mb-2">
                {{ $group['bank'] }} — {{ $group['account'] }}
                <span class="text-gray-400 font-normal">/ {{ $group['kind']->label() }} / {{ $group['year'] }}</span>
            </h2>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 bg-gray-50">
                        <th class="py-1 w-20">Број</th>
                        <th class="py-1 w-32">Датум</th>
                        <th class="py-1">Датотека</th>
                        <th class="py-1">Прикачил</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $row)
                        @if ($row['type'] === 'gap')
                            <tr class="bg-red-50 text-red-700">
                                <td class="py-1 font-medium">
                                    @if ($row['from'] === $row['to'])
                                        {{ $row['from'] }}
                                    @else
                                        {{ $row['from'] }}–{{ $row['to'] }}
                                    @endif
                                </td>
                                <td class="py-1" colspan="3">
                                    @if ($row['from'] === $row['to'])
                                        Недостига извод {{ $row['from'] }}.
                                    @else
                                        Недостигаат изводите од {{ $row['from'] }} до {{ $row['to'] }}.
                                    @endif
                                </td>
                            </tr>
                        @else
                            @php($statement = $row['statement'])
                            <tr class="hover:bg-orange-50">
                                <td class="py-1">{{ $statement->number }}</td>
                                <td class="py-1">{{ \App\Support\Format::date($statement->statement_date) }}</td>
                                <td class="py-1">
                                    @if ($statement->documents->isNotEmpty())
                                        <a href="{{ route('documents.download', [$company, $statement->documents->first()]) }}" class="text-brand hover:underline">
                                            {{ $statement->documents->first()->original_filename }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-1">{{ $statement->uploader?->name }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @empty
        <x-card>
            <p class="text-sm text-gray-500">Сè уште нема прикачени изводи.</p>
        </x-card>
    @endforelse
</div>
