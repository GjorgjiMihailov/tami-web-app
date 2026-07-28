@php
    // When creating (no $journalEntry), mount() already restricted access to
    // admin/accountant via the 'create' ability, so editing is always allowed
    // here. When viewing/editing an existing entry, defer to the 'update'
    // policy (admin/accountant only) so a read-only client sees a disabled form.
    $canEdit = $journalEntry ? auth()->user()->can('update', $journalEntry) : true;
@endphp
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $journalEntry ? 'Измени налог '.$journalEntry->displayNumber() : 'Нов налог' }} — {{ $company->name }}
    </h1>

    @unless ($canEdit)
        <p class="text-sm text-gray-500 mb-4">Имате пристап само за преглед на овој налог.</p>
    @endunless

    <x-card>
    <form wire:submit="save">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <x-input-label for="journalGroupId" value="Журнал" />
                @if ($journalEntry)
                    <div class="text-sm text-gray-700 py-2">{{ $journalEntry->journalGroup->code }} — {{ $journalEntry->journalGroup->name }}</div>
                @else
                    <select id="journalGroupId" wire:model="journalGroupId" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($groups->groupBy(fn ($g) => substr($g->code, 0, 1)) as $digit => $groupsInDigit)
                            <optgroup label="{{ $digit }}">
                                @foreach ($groupsInDigit as $g)
                                    <option value="{{ $g->id }}">{{ $g->code }} — {{ $g->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('journalGroupId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                @endif
            </div>
            <div>
                <x-input-label for="entryDate" value="Датум" />
                <input type="date" id="entryDate" wire:model="entryDate" class="border-gray-300 rounded-md shadow-sm w-full" @disabled(! $canEdit) />
                @error('entryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="description" value="Опис" />
                <x-text-input id="description" wire:model="description" class="w-full" @disabled(! $canEdit) />
            </div>
        </div>

        @error('lines') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

        <table class="min-w-full divide-y divide-gray-200 mb-4">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-1 pr-2">Сметка</th>
                    <th class="py-1 pr-2">Партнер</th>
                    <th class="py-1 pr-2">Опис</th>
                    <th class="py-1 pr-2">Должи</th>
                    <th class="py-1 pr-2">Побарува</th>
                    <th class="py-1 pr-2">Валута</th>
                    <th class="py-1 pr-2">Износ во валута</th>
                    <th class="py-1 pr-2">Курс</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.account_id" class="border-gray-300 rounded-md text-sm" @disabled(! $canEdit)>
                                <option value="">—</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.partner_id" class="border-gray-300 rounded-md text-sm" @disabled(! $canEdit)>
                                <option value="">—</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" wire:model="lines.{{ $index }}.description" class="border-gray-300 rounded-md text-sm w-32" @disabled(! $canEdit) /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-24" @disabled(! $canEdit) /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-24" @disabled(! $canEdit) /></td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.currency_code" class="border-gray-300 rounded-md text-sm" @disabled(! $canEdit)>
                                <option value="MKD">MKD</option>
                                <option value="EUR">EUR</option>
                                <option value="USD">USD</option>
                                <option value="GBP">GBP</option>
                                <option value="CHF">CHF</option>
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.foreign_amount" class="border-gray-300 rounded-md text-sm w-20" @disabled(! $canEdit) /></td>
                        <td class="py-1 pr-2 flex items-center gap-1">
                            <input type="number" step="0.000001" wire:model="lines.{{ $index }}.exchange_rate" class="border-gray-300 rounded-md text-sm w-20" @disabled(! $canEdit) />
                            @if ($line['currency_code'] !== 'MKD' && $canEdit)
                                <button type="button" wire:click="fetchRate({{ $index }})" class="text-xs text-brand hover:underline">НБРМ</button>
                            @endif
                        </td>
                        <td class="py-1">
                            @if ($canEdit)
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">✕</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($canEdit)
            <button type="button" wire:click="addLine" class="text-sm text-brand hover:underline mb-4">+ Додади ставка</button>

            <div>
                <x-primary-button type="submit">Зачувај</x-primary-button>
            </div>
        @endif
    </form>
    </x-card>

    @if ($journalEntry)
        <livewire:document-manager :documentable="$journalEntry" />
    @endif
</div>
