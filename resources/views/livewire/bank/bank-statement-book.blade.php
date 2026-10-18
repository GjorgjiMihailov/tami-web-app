<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Книжи извод бр. {{ $statement->number }} — {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">
        {{ $statement->bank }} / {{ $statement->account }} / {{ \App\Support\Format::date($statement->statement_date) }}
        @if ($statement->isBooked())
            <span class="ml-2 text-green-700 font-medium">Прокнижен</span>
        @endif
    </p>

    @error('post')
        <div class="mb-4 p-3 rounded bg-red-50 text-red-700 text-sm whitespace-pre-line">{{ $message }}</div>
    @enderror

    @if ($pendingCount > 0)
        <div class="mb-4 p-3 rounded bg-amber-50 text-amber-800 text-sm flex items-center gap-3">
            <span>{{ $pendingCount === 1 ? '1 ставка чека проверка' : $pendingCount.' ставки чекаат проверка' }}</span>
            @if ($suggestedCount > 0)
                <button type="button" wire:click="confirmSuggested"
                        wire:confirm="Ќе се потврдат {{ $suggestedCount }} предложени ставки со избраното конто или фактура. Продолжи?"
                        class="ml-auto px-3 py-1 rounded bg-green-600 text-white text-sm hover:bg-green-700">Потврди ги сите предложени ({{ $suggestedCount }})</button>
            @endif
            <button type="button" wire:click="startReview" class="{{ $suggestedCount > 0 ? '' : 'ml-auto' }} px-3 py-1 rounded bg-amber-500 text-white text-sm hover:bg-amber-600">Проверка една по една</button>
        </div>
    @endif

    @if ($reviewing && $reviewIndex !== null && isset($lines[$reviewIndex]) && in_array($reviewIndex, $reviewable, true) && ! $statement->isBooked())
        @php($i = $reviewIndex)
        @php($line = $lines[$i])
        @php($saved = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
        @php($position = array_search($i, $reviewable, true) + 1)
        <x-card class="mb-4 border-2 border-amber-400">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold text-gray-800">Проверка: {{ $position }} од {{ count($reviewable) }}</h2>
                <button type="button" wire:click="stopReview" class="text-sm text-gray-600 hover:underline">Затвори</button>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm mb-4">
                <div><dt class="text-gray-500">Датум</dt><dd>{{ \App\Support\Format::date($line['line_date']) }}</dd></div>
                <div><dt class="text-gray-500">Насока и износ</dt>
                    <dd class="font-semibold">{{ $line['direction'] === 'in' ? 'Уплата' : 'Исплата' }} {{ \App\Support\Format::money($line['amount'], '') }}</dd></div>
                <div><dt class="text-gray-500">Другата страна</dt>
                    <dd>{{ $saved?->counterparty_name ?? '—' }} @if ($saved?->counterparty_account)<span class="text-gray-500">· {{ $saved->counterparty_account }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Повикување на број</dt><dd>{{ $saved?->reference_number ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-gray-500">Опис од банката</dt><dd class="whitespace-pre-line">{{ $saved?->description ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-gray-500">Што мисли програмата</dt>
                    <dd>{{ $saved?->match_confidence?->label() }} — {{ $saved?->match_reason }}</dd></div>
            </dl>

            <div class="flex flex-wrap gap-3 items-end mb-3">
                <div class="w-48">
                    <x-input-label value="Вид" />
                    <select wire:model.live="lines.{{ $i }}.kind" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach (\App\Support\Bank\LineKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-64" x-data="{ jepPartners: @js($partnersForJs) }">
                    <x-input-label value="Партнер" />
                    @php($reviewPartner = $partners->firstWhere('id', $line['partner_id'] ?? null))
                    <div class="relative" x-data="journalEntryPicker(jepPartners, 'lines.{{ $i }}.partner_id', @js($reviewPartner?->name ?? ''))"
                         data-wire-model="lines.{{ $i }}.partner_id" data-label="{{ $reviewPartner?->name ?? '' }}"
                         @click.outside="open = false">
                        <input type="text" x-model="query" @focus="open = true; $event.target.select()" @input="onInput()"
                               @keydown="onKeydown($event)" :title="query" autocomplete="off"
                               placeholder="Почни да пишуваш…" class="border-gray-300 rounded-md text-sm w-full" />
                        <div x-show="open && filtered.length" x-cloak
                             class="absolute z-20 bg-white border border-sand rounded-md shadow-md mt-1 max-h-56 overflow-y-auto w-full">
                            <template x-for="(item, index) in filtered" :key="item.id">
                                <div @click="select(item)" @mouseenter="active = index"
                                     :class="index === active ? 'bg-gray-100' : ''"
                                     class="px-2 py-1 text-sm cursor-pointer" x-text="item.label"></div>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="w-72">
                    @if ($line['kind'] === 'invoice_payment')
                        <x-input-label value="Фактура" />
                        <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— фактура —</option>
                            @foreach ($this->invoiceOptions($i) as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @if (! empty($line['invoice_id']))
                            @php($payments = $this->paymentOptions($i))
                            @if ($payments !== [])
                                <select wire:model.live="lines.{{ $i }}.existing_payment_id" class="mt-1 border-gray-300 rounded-md text-sm w-full">
                                    <option value="">— ново плаќање —</option>
                                    @foreach ($payments as $payment)
                                        <option value="{{ $payment['id'] }}">Веќе внесено: {{ $payment['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        @endif
                    @else
                        <x-input-label value="Конто" />
                        <select wire:model.live="lines.{{ $i }}.account_id" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— конто —</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            </div>

            @error('review') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

            <div class="flex gap-3 items-center">
                <x-primary-button type="button" wire:click="confirmAndNext">Потврди и следна</x-primary-button>
                <x-secondary-button type="button" wire:click="skipLine">Прескокни</x-secondary-button>
                <x-secondary-button type="button" wire:click="previousLine">Назад</x-secondary-button>
            </div>
        </x-card>
    @endif

    <x-card class="mb-4">
        <div class="flex flex-wrap gap-4">
            <div class="w-64">
                <x-input-label for="openingBalance" value="Почетна состојба (Побарува на изводот = +, Долгува = −)" />
                <x-text-input id="openingBalance" wire:model.live.debounce.300ms="openingBalance" class="w-full text-right" :disabled="$statement->isBooked()" />
                @error('openingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-64">
                <x-input-label for="closingBalance" value="Крајна состојба (Побарува = +, Долгува = −)" />
                <x-text-input id="closingBalance" wire:model.live.debounce.300ms="closingBalance" class="w-full text-right" :disabled="$statement->isBooked()" />
                @error('closingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
    </x-card>

    <x-card class="mb-4">
        @php($locked = $statement->isBooked())
        @if ($splitNotice)
            <div class="mb-3 p-2 rounded bg-amber-50 text-amber-800 text-sm">{{ $splitNotice }}</div>
        @endif
        {{-- Тастатура: Tab оди од поле до поле; Tab на последното поле од последната ставка додава нова. --}}
        <div class="overflow-x-auto" x-data="{
                jepAccounts: @js($accountsForJs),
                jepPartners: @js($partnersForJs),
                async addAndFocus(n) {
                    await this.$wire.addLine();
                    this.$nextTick(() => document.querySelector(`[data-line-first='${n}'] input`)?.focus());
                },
            }">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 bg-gray-50">
                    <th class="py-1 pr-2 w-20">#</th>
                    <th class="py-1 pr-2 w-56">Конто</th>
                    <th class="py-1 pr-2 w-48">Партнер (кооперант)</th>
                    <th class="py-1 pr-2 w-32">Датум на извод</th>
                    <th class="py-1 pr-2 w-20">Бр. на извод</th>
                    <th class="py-1 pr-2 w-60">Затворање на фактура</th>
                    <th class="py-1 pr-2 w-28 text-right">Должи<span class="block font-normal text-gray-400">исплата</span></th>
                    <th class="py-1 pr-2 w-28 text-right">Побарува<span class="block font-normal text-gray-400">уплата</span></th>
                    <th class="py-1 pr-2 min-w-[16rem]">Опис</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $i => $line)
                    @php($saved = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
                    @php($hasInvoice = $line['kind'] === 'invoice_payment' && ! empty($line['invoice_id']))
                    @php($openInvoices = empty($line['partner_id']) ? [] : $this->invoiceOptions($i))
                    <tr wire:key="line-{{ $line['_key'] }}" class="align-top border-t border-gray-100">
                        <td class="py-1 pr-2">
                            <span class="text-gray-500">{{ $i + 1 }}</span>
                            @if ($saved?->match_confidence)
                                @php($confidence = $saved->match_confidence)
                                <span class="block mt-1 px-1.5 py-0.5 rounded text-xs w-fit {{ ['sure' => 'bg-green-100 text-green-800', 'check' => 'bg-amber-100 text-amber-800', 'unclear' => 'bg-red-100 text-red-800'][$confidence->value] }}"
                                      title="{{ $saved->match_reason }}">{{ $confidence->label() }}</span>
                                @if ($confidence->needsReview() && ! empty($line['reviewed']))
                                    <span class="block text-xs text-green-700">✓ проверено</span>
                                @endif
                            @endif
                        </td>

                        {{-- Конто --}}
                        <td class="py-1 pr-2">
                            @if ($hasInvoice)
                                @php($closing = $this->closingAccount($i))
                                @if ($closing !== null)
                                    <span class="block py-1 text-xs text-gray-700">{{ $closing->code }} {{ $closing->name }}
                                        <span class="block text-gray-400">затвора на сметката од отворањето (група {{ mb_substr($closing->code, 0, 3) }})</span>
                                    </span>
                                @else
                                    <span class="block py-1 text-xs text-gray-500">Затворање на фактура</span>
                                @endif
                            @else
                                @php($selectedAccount = $accounts->firstWhere('id', $line['account_id'] ?? null))
                                <div class="relative" data-line-first="{{ $i }}"
                                     x-data="journalEntryPicker(jepAccounts, 'lines.{{ $i }}.account_id', @js($selectedAccount ? $selectedAccount->code.' — '.$selectedAccount->name : ''))"
                                     @click.outside="open = false">
                                    <input type="text" x-model="query" @focus="open = true; $event.target.select()" @input="onInput()"
                                           @keydown="onKeydown($event)" :title="query" autocomplete="off"
                                           placeholder="Шифра или име…" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked) />
                                    <div x-show="open && filtered.length" x-cloak
                                         class="absolute z-20 bg-white border border-sand rounded-md shadow-md mt-1 max-h-56 overflow-y-auto w-80">
                                        <template x-for="(item, index) in filtered" :key="item.id">
                                            <div @click="select(item)" @mouseenter="active = index"
                                                 :class="index === active ? 'bg-gray-100' : ''"
                                                 class="px-2 py-1 text-xs cursor-pointer" x-text="item.label"></div>
                                        </template>
                                    </div>
                                </div>
                            @endif
                        </td>

                        {{-- Партнер --}}
                        <td class="py-1 pr-2">
                            @php($tablePartner = $partners->firstWhere('id', $line['partner_id'] ?? null))
                            <div class="relative" x-data="journalEntryPicker(jepPartners, 'lines.{{ $i }}.partner_id', @js($tablePartner?->name ?? ''))"
                                 data-wire-model="lines.{{ $i }}.partner_id" data-label="{{ $tablePartner?->name ?? '' }}"
                                 @click.outside="open = false">
                                <input type="text" x-model="query" @focus="open = true; $event.target.select()" @input="onInput()"
                                       @keydown="onKeydown($event)" :title="query" autocomplete="off"
                                       placeholder="Партнер…" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked) />
                                <div x-show="open && filtered.length" x-cloak
                                     class="absolute z-20 bg-white border border-sand rounded-md shadow-md mt-1 max-h-56 overflow-y-auto w-80">
                                    <template x-for="(item, index) in filtered" :key="item.id">
                                        <div @click="select(item)" @mouseenter="active = index"
                                             :class="index === active ? 'bg-gray-100' : ''"
                                             class="px-2 py-1 text-xs cursor-pointer" x-text="item.label"></div>
                                    </template>
                                </div>
                            </div>
                        </td>

                        {{-- Датум на изводот --}}
                        <td class="py-1 pr-2">
                            <x-text-input type="date" wire:model="lines.{{ $i }}.line_date" class="w-full text-xs py-1" :disabled="$locked" />
                        </td>

                        {{-- Број на изводот --}}
                        <td class="py-1 pr-2 text-xs text-gray-600"><span class="block py-1">{{ $statement->number }}</span></td>

                        {{-- Затворање на отворени фактури --}}
                        <td class="py-1 pr-2">
                            @if (empty($line['partner_id']))
                                <span class="block py-1 text-xs text-gray-400">Изберете партнер</span>
                            @elseif ($openInvoices === [] && empty($line['invoice_id']))
                                <span class="block py-1 text-xs text-gray-400">Нема отворени фактури</span>
                            @else
                                <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                    <option value="">— без затворање —</option>
                                    @foreach ($openInvoices as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>

                                @if (! $locked && count($openInvoices) > 1 && \App\Support\Bcmath::isPlainNumber((string) $line['amount']))
                                    {{-- Една уплата за повеќе фактури: означете ги, ставката се дели по фактура (најстарата прва). --}}
                                    <div x-data="{ open: false, picked: [] }" class="mt-1">
                                        <button type="button" @click="open = ! open" class="text-xs text-brand hover:underline">Затвори повеќе фактури…</button>
                                        <div x-show="open" x-cloak class="mt-1 border border-sand rounded-md p-2 bg-white">
                                            @foreach ($openInvoices as $option)
                                                <label class="flex items-start gap-2 text-xs py-0.5 cursor-pointer">
                                                    <input type="checkbox" value="{{ $option['id'] }}" x-model="picked" class="mt-0.5 rounded border-gray-300">
                                                    <span>{{ $option['label'] }}</span>
                                                </label>
                                            @endforeach
                                            <button type="button"
                                                    @click="$wire.splitAcrossInvoices({{ $i }}, picked.map(Number)); open = false; picked = []"
                                                    :disabled="picked.length === 0"
                                                    class="mt-2 px-2 py-1 rounded bg-brand text-white text-xs disabled:opacity-40">Распредели ја уплатата</button>
                                            <p class="mt-1 text-[11px] text-gray-500">Најстарите фактури се затвораат прво, секоја до своето салдо, додека не се потроши износот.</p>
                                        </div>
                                    </div>
                                @endif

                                @if ($hasInvoice)
                                    @php($picked = collect($openInvoices)->firstWhere('id', (int) $line['invoice_id']))
                                    @if ($picked !== null && \App\Support\Bcmath::isPlainNumber((string) $line['amount']))
                                        @php($rest = bcsub($picked['balance'], (string) $line['amount'], 2))
                                        @if (bccomp($rest, '0', 2) > 0)
                                            <span class="block mt-1 text-xs text-amber-700">делумно затворање — останува {{ \App\Support\Format::money($rest, '') }}</span>
                                        @elseif (bccomp($rest, '0', 2) === 0)
                                            <span class="block mt-1 text-xs text-green-700">целосно затворање</span>
                                        @else
                                            <span class="block mt-1 text-xs text-red-700">износот е поголем од салдото</span>
                                        @endif
                                    @endif

                                    @php($payments = $this->paymentOptions($i))
                                    @if ($payments !== [])
                                        <select wire:model.live="lines.{{ $i }}.existing_payment_id" class="mt-1 border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                            <option value="">— ново плаќање —</option>
                                            @foreach ($payments as $payment)
                                                <option value="{{ $payment['id'] }}">Веќе внесено: {{ $payment['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                @endif
                            @endif
                        </td>

                        {{-- Должи = исплата, Побарува = уплата (од гледна точка на банката и на избраното конто). --}}
                        <td class="py-1 pr-2">
                            <x-text-input inputmode="decimal" wire:model.live.debounce.300ms="lines.{{ $i }}.debit" class="w-full text-right text-xs py-1" :disabled="$locked" />
                        </td>
                        <td class="py-1 pr-2">
                            <x-text-input inputmode="decimal" wire:model.live.debounce.300ms="lines.{{ $i }}.credit" class="w-full text-right text-xs py-1" :disabled="$locked" />
                            @error("lines.$i.amount") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                        </td>

                        {{-- Опис: на крај, ситен фонт --}}
                        <td class="py-1 pr-2">
                            <textarea wire:model="lines.{{ $i }}.description" rows="2" @disabled($locked)
                                      @if ($loop->last && ! $locked) @keydown.tab="if (! $event.shiftKey) { $event.preventDefault(); addAndFocus({{ $i + 1 }}); }" @endif
                                      class="w-full border-gray-300 rounded-md text-xs text-gray-600 leading-snug py-1"></textarea>
                            @if ($saved !== null && ($saved->counterparty_name || $saved->counterparty_account || $saved->reference_number))
                                <div class="mt-0.5 text-xs text-gray-500">
                                    {{ $saved->counterparty_name }}
                                    @if ($saved->counterparty_account) · {{ $saved->counterparty_account }} @endif
                                    @if ($saved->reference_number) · реф. {{ $saved->reference_number }} @endif
                                </div>
                            @endif
                        </td>
                        <td class="py-1">
                            @unless ($locked)
                                <button type="button" tabindex="-1" wire:click="removeLine({{ $i }})" class="text-red-600 text-xs">Бриши</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        @unless ($statement->isBooked())
            <button type="button" wire:click="addLine" class="mt-3 text-brand text-sm hover:underline">+ Додај ставка</button>
            <span class="ml-3 text-xs text-gray-400">Tab оди на следното поле; Tab на последното поле додава нова ставка.</span>
        @endunless

        <p class="mt-2 text-xs text-gray-500">
            Износот се впишува во колоната на избраното конто: <strong>Должи</strong> за исплата, <strong>Побарува</strong> за уплата.
            Банката (1000) се книжи автоматски на спротивната страна.
        </p>
    </x-card>

    <x-card class="mb-4">
        @php($diff = $this->difference)
        <p class="text-sm mb-2 flex flex-wrap gap-x-6 font-semibold text-gray-800">
            <span>Вкупно должи (исплати): {{ \App\Support\Format::money($this->totals['debit'], '') }}</span>
            <span>Вкупно побарува (уплати): {{ \App\Support\Format::money($this->totals['credit'], '') }}</span>
        </p>
        <p class="text-sm">
            Разлика (почетна + движење − крајна):
            @if ($diff === null)
                <span class="text-gray-500">внесете ги состојбите</span>
            @elseif (bccomp($diff, '0', 2) === 0)
                <span class="text-green-700 font-semibold">0,00 — се совпаѓа</span>
            @else
                <span class="text-red-700 font-semibold">{{ \App\Support\Format::money($diff, '') }}</span>
            @endif
        </p>
        @if ($problems !== [])
            <ul class="mt-2 text-sm text-red-700 list-disc pl-5">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <div class="flex gap-3 items-center">
        @if ($statement->isBooked())
            <x-secondary-button wire:click="reopen" wire:confirm="Отворањето на изводот го брише неговиот налог. Продолжи?">Отвори за измена</x-secondary-button>
        @else
            <x-secondary-button wire:click="save">Зачувај нацрт</x-secondary-button>
            <x-primary-button type="button" wire:click="post">Потврди и прокнижи</x-primary-button>
        @endif
        <a href="{{ route('bank-statements.index', $company) }}" class="text-sm text-gray-600 hover:underline">Назад</a>

        {{-- Движење меѓу изводите на оваа сметка; нацртот се зачувува пред да се замине. --}}
        <div class="ml-auto flex gap-2">
            <x-secondary-button type="button" wire:click="goToSibling('previous')" :disabled="$siblings['previous'] === null">
                ← {{ $siblings['previous'] ? 'Извод '.$siblings['previous']['number'] : 'Нема претходен' }}
            </x-secondary-button>
            <x-secondary-button type="button" wire:click="goToSibling('next')" :disabled="$siblings['next'] === null">
                {{ $siblings['next'] ? 'Извод '.$siblings['next']['number'] : 'Нема следен' }} →
            </x-secondary-button>
        </div>
    </div>
</div>
