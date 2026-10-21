<div x-data="{ jepAccounts: @js($accountsForJs) }">
    <a href="{{ route('bank-statements.index', $company) }}" class="text-sm text-brand hover:underline">← Банкарски документи</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-1 mb-1">Запомнати конта — {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">
        Што програмата го нуди при увоз на извод, по сметка на другата страна, насока и вид на текст.
        Промената важи за следните увози; веќе прокнижените изводи не се менуваат.
    </p>

    <x-card>
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Барај по име или текст…"
                   class="border-gray-300 rounded-md text-sm py-1 w-full sm:w-80" />
        </div>

        @if ($rules->isEmpty())
            <p class="text-sm text-gray-500">
                {{ trim($search) === '' ? 'Сè уште нема запомнати конта. Се создаваат кога ќе прокнижите извод со ставки на конто.' : 'Нема резултат за пребарувањето.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50">
                            <th class="py-1 pr-2">Друга страна</th>
                            <th class="py-1 pr-2 w-20">Насока</th>
                            <th class="py-1 pr-2">Вид на текст</th>
                            <th class="py-1 pr-2 w-80">Конто</th>
                            <th class="py-1 pr-2">Партнер</th>
                            <th class="py-1 pr-2 w-20 text-right">Пати</th>
                            <th class="py-1 pr-2 w-28">Последно</th>
                            <th class="py-1 w-20"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            @php($label = $rule->account ? $rule->account->code.' — '.$rule->account->name : '')
                            <tr class="border-t border-sand align-top" wire:key="rule-{{ $rule->id }}">
                                <td class="py-1 pr-2">
                                    <div>{{ $rule->name_seen ?? '—' }}</div>
                                    <div class="text-xs text-gray-400">{{ $rule->account_key }}</div>
                                </td>
                                <td class="py-1 pr-2">{{ $rule->direction === 'in' ? 'Уплата' : 'Исплата' }}</td>
                                <td class="py-1 pr-2">
                                    <div>{{ $rule->pattern }}</div>
                                    @if ($rule->example_text)
                                        <div class="text-xs text-gray-400">пример: {{ $rule->example_text }}</div>
                                    @endif
                                </td>
                                <td class="py-1 pr-2">
                                    <div class="relative"
                                         x-data="journalEntryPicker(jepAccounts, 'accountIds.{{ $rule->id }}', @js($label))"
                                         data-wire-model="accountIds.{{ $rule->id }}" data-label="{{ $label }}"
                                         @click.outside="open = false">
                                        <input type="text" x-model="query" @focus="open = true; $event.target.select()" @input="onInput()"
                                               @keydown="onKeydown($event)" :title="query" autocomplete="off"
                                               placeholder="Шифра или име…" class="border-gray-300 rounded-md text-xs py-1 w-full" />
                                        <div x-show="open && filtered.length" x-cloak
                                             class="absolute z-20 bg-white border border-sand rounded-md shadow-md mt-1 max-h-56 overflow-y-auto w-80">
                                            <template x-for="(item, index) in filtered" :key="item.id">
                                                <div @click="select(item)" @mouseenter="active = index"
                                                     :class="index === active ? 'bg-gray-100' : ''"
                                                     class="px-2 py-1 text-xs cursor-pointer" x-text="item.label"></div>
                                            </template>
                                        </div>
                                    </div>
                                    @error('accountIds.'.$rule->id) <span class="text-red-600 text-xs block">{{ $message }}</span> @enderror
                                    @unless ($rule->account)
                                        <span class="text-amber-700 text-xs block">Контото повеќе не е употребливо — изберете ново.</span>
                                    @endunless
                                </td>
                                <td class="py-1 pr-2">{{ $rule->partner?->name ?? '—' }}</td>
                                <td class="py-1 pr-2 text-right">{{ $rule->times_used }}</td>
                                <td class="py-1 pr-2">{{ $rule->last_used_at ? \App\Support\Format::date($rule->last_used_at) : '—' }}</td>
                                <td class="py-1 text-right">
                                    <button type="button" wire:click="deleteRule({{ $rule->id }})"
                                            wire:confirm="Да се избрише ова правило? Следниот пат ставката од овој вид ќе дојде без предложено конто."
                                            class="text-red-600 hover:underline text-xs">Избриши</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $rules->links() }}</div>
        @endif
    </x-card>
</div>
