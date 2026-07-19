<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $journalEntry ? 'Edit Journal Entry #'.$journalEntry->entry_number : 'New Journal Entry' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="bg-white shadow rounded-md p-4">
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <x-input-label for="entryDate" value="Date" />
                <input type="date" id="entryDate" wire:model="entryDate" class="border-gray-300 rounded-md shadow-sm w-full" />
                @error('entryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="description" value="Description" />
                <x-text-input id="description" wire:model="description" class="w-full" />
            </div>
        </div>

        @error('lines') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

        <table class="min-w-full divide-y divide-gray-200 mb-4">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-1 pr-2">Account</th>
                    <th class="py-1 pr-2">Partner</th>
                    <th class="py-1 pr-2">Description</th>
                    <th class="py-1 pr-2">Debit</th>
                    <th class="py-1 pr-2">Credit</th>
                    <th class="py-1 pr-2">Currency</th>
                    <th class="py-1 pr-2">Foreign amt.</th>
                    <th class="py-1 pr-2">Rate</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.account_id" class="border-gray-300 rounded-md text-sm">
                                <option value="">—</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.partner_id" class="border-gray-300 rounded-md text-sm">
                                <option value="">—</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" wire:model="lines.{{ $index }}.description" class="border-gray-300 rounded-md text-sm w-32" /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-24" /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-24" /></td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.currency_code" class="border-gray-300 rounded-md text-sm">
                                <option value="MKD">MKD</option>
                                <option value="EUR">EUR</option>
                                <option value="USD">USD</option>
                                <option value="GBP">GBP</option>
                                <option value="CHF">CHF</option>
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.foreign_amount" class="border-gray-300 rounded-md text-sm w-20" /></td>
                        <td class="py-1 pr-2 flex items-center gap-1">
                            <input type="number" step="0.000001" wire:model="lines.{{ $index }}.exchange_rate" class="border-gray-300 rounded-md text-sm w-20" />
                            @if ($line['currency_code'] !== 'MKD')
                                <button type="button" wire:click="fetchRate({{ $index }})" class="text-xs text-indigo-600 hover:underline">NBRM</button>
                            @endif
                        </td>
                        <td class="py-1">
                            <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">✕</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <button type="button" wire:click="addLine" class="text-sm text-indigo-600 hover:underline mb-4">+ Add line</button>

        <div>
            <x-primary-button type="submit">Save</x-primary-button>
        </div>
    </form>
</div>
