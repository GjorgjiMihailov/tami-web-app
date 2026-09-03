<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Други трошоци — {{ $company->name }}</h1>
        <span class="text-xs text-gray-400">Работна година {{ $workingYear }}</span>
    </div>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Внеси трошок</h2>
        <form wire:submit="save" class="flex flex-wrap gap-3 items-end">
            <div class="w-40">
                <x-input-label for="costDate" value="Датум" />
                <x-text-input id="costDate" type="date" wire:model="costDate" class="w-full" />
                @error('costDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="flex-1 min-w-[16rem]">
                <x-input-label for="description" value="Што е трошокот" />
                <x-text-input id="description" wire:model="description" class="w-full" />
                @error('description') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-32">
                <x-input-label for="amount" value="Износ" />
                <x-text-input id="amount" wire:model="amount" class="w-full text-right" />
                @error('amount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="newFile" value="Документ" />
                <input type="file" id="newFile" wire:model="newFile" class="text-sm">
                @error('newFile') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-primary-button type="submit">Зачувај</x-primary-button>
        </form>
    </x-card>

    <x-card>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1 w-32">Датум</th>
                    <th class="py-1">Што е трошокот</th>
                    <th class="py-1 w-32 text-right">Износ</th>
                    <th class="py-1">Документ</th>
                    <th class="py-1">Внел</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($costs as $cost)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">{{ \App\Support\Format::date($cost->cost_date) }}</td>
                        <td class="py-1">{{ $cost->description }}</td>
                        <td class="py-1 text-right">{{ \App\Support\Format::money($cost->amount) }}</td>
                        <td class="py-1">
                            @if ($cost->documents->isNotEmpty())
                                <a href="{{ route('documents.download', [$company, $cost->documents->first()]) }}" class="text-brand hover:underline">
                                    {{ $cost->documents->first()->original_filename }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1">{{ $cost->creator?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Нема внесени трошоци за {{ $workingYear }}.</td></tr>
                @endforelse
            </tbody>
            @if ($costs->isNotEmpty())
                <tfoot>
                    <tr class="border-t border-gray-200 font-semibold text-gray-800">
                        <td class="py-1" colspan="2">Вкупно {{ $workingYear }}</td>
                        <td class="py-1 text-right">{{ \App\Support\Format::money($total) }}</td>
                        <td class="py-1" colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </x-card>
</div>
