@php
    // When creating (no $journalEntry), mount() already restricted access to
    // admin/accountant via the 'create' ability, so editing is always allowed
    // here. When viewing/editing an existing entry, defer to the 'update'
    // policy (admin/accountant only) so a read-only client sees a disabled form.
    $canEdit = $journalEntry ? auth()->user()->can('update', $journalEntry) : true;
@endphp
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
            <span>{{ $journalEntry ? 'Измени налог '.$journalEntry->displayNumber() : 'Нов налог' }} — {{ $company->name }}</span>
            @if ($journalEntry && (int) $journalEntry->fiscal_year !== $workingYear)
                <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">Запис од {{ $journalEntry->fiscal_year }}</span>
            @endif
        </h1>

        @if ($journalEntry)
            <div class="flex items-center gap-2 text-sm">
                <button type="button" wire:click="goToFirst" @disabled(! $hasPrevious) class="px-2 py-1 border rounded-md disabled:opacity-30">⏮</button>
                <button type="button" wire:click="goToPrevious" @disabled(! $hasPrevious) class="px-2 py-1 border rounded-md disabled:opacity-30">◁</button>
                <button type="button" wire:click="goToNext" @disabled(! $hasNext) class="px-2 py-1 border rounded-md disabled:opacity-30">▷</button>
                <button type="button" wire:click="goToLast" @disabled(! $hasNext) class="px-2 py-1 border rounded-md disabled:opacity-30">⏭</button>
                <a href="{{ route('accounting.journal-entries.pdf', [$company, $journalEntry]) }}" target="_blank">
                    <x-secondary-button type="button">Печати</x-secondary-button>
                </a>
            </div>
        @endif
    </div>

    @unless ($canEdit)
        <p class="text-sm text-gray-500 mb-4">Имате пристап само за преглед на овој налог.</p>
    @endunless

    <x-card>
    <form wire:submit="save">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <x-input-label for="journalGroupId" value="Журнал" />
                @if ($journalEntry)
                    <div class="text-sm text-gray-700 py-2">
                        @if ($journalEntry->journalGroup)
                            {{ $journalEntry->journalGroup->code }} — {{ $journalEntry->journalGroup->name }}
                        @else
                            —
                        @endif
                    </div>
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
                <input type="date" id="entryDate" wire:model.live="entryDate" class="border-gray-300 rounded-md shadow-sm w-full" @disabled(! $canEdit) />
                @error('entryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="description" value="Опис" />
                <x-text-input id="description" wire:model="description" class="w-full" @disabled(! $canEdit) />
            </div>
        </div>

        <div class="mb-4 hidden md:block">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model.live="isForeignCurrency" @disabled(! $canEdit) />
                Овој налог е во девизи
            </label>
        </div>

        @error('lines') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror
        @error('delete') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

        <div x-data="{ jepAccounts: @js($accountsForJs), jepPartners: @js($partnersForJs) }">
        <table class="min-w-full divide-y divide-gray-200 mb-4 hidden md:table">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 pr-2">Сметка</th>
                    <th class="py-1 pr-2">Партнер</th>
                    <th class="py-1 pr-2">Опис</th>
                    <th class="py-1 pr-2">Датум</th>
                    <th class="py-1 pr-2">Должи</th>
                    <th class="py-1 pr-2">Побарува</th>
                    @if ($isForeignCurrency)
                        <th class="py-1 pr-2">Валута</th>
                        <th class="py-1 pr-2">Износ во валута</th>
                        <th class="py-1 pr-2">Курс</th>
                    @endif
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr wire:key="d-line-{{ $line['_key'] }}">
                        @php
                            $selectedAccount = $accounts->firstWhere('id', $line['account_id']);
                            $accountLabel = $selectedAccount ? $selectedAccount->code.' — '.$selectedAccount->name : '';
                        @endphp
                        <td class="py-1 pr-2 relative" x-data="journalEntryPicker(jepAccounts, 'lines.{{ $index }}.account_id', @js($accountLabel))" @click.outside="open = false">
                            <input type="text" x-model="query" @focus="open = true" @input="onInput()"
                                   placeholder="Код или име..." class="border-gray-300 rounded-md text-sm w-40" @disabled(! $canEdit) />
                            <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-48 overflow-y-auto w-64">
                                <template x-for="item in filtered" :key="item.id">
                                    <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                </template>
                            </div>
                        </td>
                        @php
                            $selectedPartner = $partners->firstWhere('id', $line['partner_id']);
                            $partnerLabel = $selectedPartner?->name ?? '';
                        @endphp
                        <td class="py-1 pr-2 relative" x-data="journalEntryPicker(jepPartners, 'lines.{{ $index }}.partner_id', @js($partnerLabel))" @click.outside="open = false">
                            <input type="text" x-model="query" @focus="open = true" @input="onInput()"
                                   placeholder="Партнер..." class="border-gray-300 rounded-md text-sm w-40" @disabled(! $canEdit) />
                            <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-48 overflow-y-auto w-64">
                                <template x-for="item in filtered" :key="item.id">
                                    <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                </template>
                            </div>
                        </td>
                        <td class="py-1 pr-2"><input type="text" wire:model="lines.{{ $index }}.description" class="border-gray-300 rounded-md text-sm w-32" @disabled(! $canEdit) /></td>
                        @php $isLate = $line['line_date'] > $entryDate; @endphp
                        <td class="py-1 pr-2 {{ $isLate ? 'bg-red-50' : '' }}">
                            <input type="date" wire:model.live="lines.{{ $index }}.line_date"
                                   class="rounded-md text-sm {{ $isLate ? 'border-red-400 text-red-700' : 'border-gray-300' }}"
                                   @disabled(! $canEdit) />
                        </td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model.live.debounce.300ms="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-24" @disabled(! $canEdit) /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model.live.debounce.300ms="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-24" @disabled(! $canEdit) /></td>
                        @if ($isForeignCurrency)
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
                        @endif
                        <td class="py-1">
                            @if ($canEdit)
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">✕</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="md:hidden space-y-3 mb-4">
            @foreach ($lines as $index => $line)
                @php $isLate = $line['line_date'] > $entryDate; @endphp
                <div wire:key="m-line-{{ $line['_key'] }}" class="border border-gray-200 rounded-xl p-3 text-sm {{ $isLate ? 'bg-red-50' : '' }}">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-medium text-gray-500">Ставка {{ $index + 1 }}</span>
                        @if ($canEdit)
                            <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-xs">Отстрани</button>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500">Сметка</label>
                            @php
                                $mobileSelectedAccount = $accounts->firstWhere('id', $line['account_id']);
                                $mobileAccountLabel = $mobileSelectedAccount ? $mobileSelectedAccount->code.' — '.$mobileSelectedAccount->name : '';
                            @endphp
                            <div x-data="journalEntryPicker(jepAccounts, 'lines.{{ $index }}.account_id', @js($mobileAccountLabel))" @click.outside="open = false" class="relative">
                                <input type="text" x-model="query" @focus="open = true" @input="onInput()" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                                <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-40 overflow-y-auto w-full">
                                    <template x-for="item in filtered" :key="item.id">
                                        <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Датум</label>
                            <input type="date" wire:model.live="lines.{{ $index }}.line_date" class="rounded-md text-sm w-full {{ $isLate ? 'border-red-400 text-red-700' : 'border-gray-300' }}" @disabled(! $canEdit) />
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Должи</label>
                            <input type="number" step="0.01" wire:model.live.debounce.300ms="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Побарува</label>
                            <input type="number" step="0.01" wire:model.live.debounce.300ms="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        </div>

        @if ($canEdit)
            <button type="button" wire:click="addLine" class="text-sm text-brand hover:underline mb-4">+ Додади ставка</button>

            <div>
                <x-primary-button type="submit">Зачувај</x-primary-button>
                @if ($journalEntry)
                    <x-danger-button type="button" wire:click="delete" wire:confirm="Да се избрише трајно овој налог? Ова не може да се врати." class="ml-2">Избриши</x-danger-button>
                @endif
            </div>
        @endif

        @php $isBalanced = bccomp((string) $totalDebit, (string) $totalCredit, 2) === 0; @endphp
        <div class="sticky bottom-0 bg-white border-t border-gray-200 px-4 py-3 flex flex-wrap justify-end gap-6 text-sm font-semibold {{ $isBalanced ? 'text-gray-800' : 'text-red-600' }}">
            <span>Вкупно должи: {{ \App\Support\Format::money($totalDebit) }}</span>
            <span>Вкупно побарува: {{ \App\Support\Format::money($totalCredit) }}</span>
            <span>Салдо: {{ \App\Support\Format::money($totalDebit - $totalCredit) }}</span>
        </div>
    </form>
    </x-card>

    @if ($journalEntry)
        <livewire:document-manager :documentable="$journalEntry" />
    @endif
</div>
