<div>
    @php($locked = $statement->isBooked())
    @php($currency = $statement->currency)
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Книжи девизен извод бр. {{ $statement->number }} — {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">
        {{ $statement->bank }} / {{ $statement->account }} / {{ $currency }} / {{ \App\Support\Format::date($statement->statement_date) }}
        @if ($locked)
            <span class="ml-2 text-green-700 font-medium">Прокнижен</span>
        @endif
    </p>

    @error('post')
        <div class="mb-4 p-3 rounded bg-red-50 text-red-700 text-sm whitespace-pre-line">{{ $message }}</div>
    @enderror

    <x-card class="mb-4">
        <div class="flex flex-wrap gap-4">
            <div class="w-64">
                <x-input-label for="openingBalance" value="Почетна состојба ({{ $currency }})" />
                <x-text-input id="openingBalance" wire:model.live.debounce.300ms="openingBalance" class="w-full text-right" :disabled="$locked" />
                @error('openingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-64">
                <x-input-label for="closingBalance" value="Крајна состојба ({{ $currency }})" />
                <x-text-input id="closingBalance" wire:model.live.debounce.300ms="closingBalance" class="w-full text-right" :disabled="$locked" />
                @error('closingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
    </x-card>

    <x-card class="mb-4">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 bg-gray-50">
                        <th class="py-1 pr-2 w-8">#</th>
                        <th class="py-1 pr-2 w-44">Ставка</th>
                        <th class="py-1 pr-2 w-48">Партнер</th>
                        <th class="py-1 pr-2 w-64">Фактура</th>
                        <th class="py-1 pr-2 w-32">Датум</th>
                        <th class="py-1 pr-2 w-28 text-right">Износ ({{ $currency }})</th>
                        <th class="py-1 pr-2 w-40 text-right">Курс на денот</th>
                        <th class="py-1 pr-2 w-28 text-right">Во денари</th>
                        <th class="py-1 pr-2 min-w-[12rem]">Опис</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $i => $line)
                        @php($isSale = $line['kind'] === 'currency_sale')
                        @php($options = $this->invoiceOptions($i))
                        <tr wire:key="line-{{ $line['_key'] }}" class="align-top border-t border-gray-100">
                            <td class="py-1 pr-2 text-gray-500">{{ $i + 1 }}</td>
                            <td class="py-1 pr-2">
                                <select wire:model.live="lines.{{ $i }}.kind" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                    <option value="invoice_payment">Наплата на фактура</option>
                                    <option value="currency_sale">Продажба на девиза</option>
                                </select>
                                @error("lines.$i.kind") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </td>
                            <td class="py-1 pr-2">
                                @if ($isSale)
                                    <span class="block py-1 text-xs text-gray-400">1030 → 1039</span>
                                @else
                                    <select wire:model.live="lines.{{ $i }}.partner_id" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                        <option value="">— партнер —</option>
                                        @foreach ($partners as $partner)
                                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                            <td class="py-1 pr-2">
                                @if ($isSale)
                                    <span class="block py-1 text-xs text-gray-400">—</span>
                                @elseif (empty($line['partner_id']))
                                    <span class="block py-1 text-xs text-gray-400">Изберете партнер</span>
                                @elseif ($options === [] && empty($line['invoice_id']))
                                    <span class="block py-1 text-xs text-gray-400">Нема отворени фактури во {{ $currency }}</span>
                                @else
                                    <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                        <option value="">— изберете фактура —</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @php($payments = $this->paymentOptions($i))
                                    @if ($payments !== [])
                                        <select wire:model.live="lines.{{ $i }}.existing_payment_id" class="mt-1 border-gray-300 rounded-md text-xs py-1 w-full" @disabled($locked)>
                                            <option value="">— нова наплата —</option>
                                            @foreach ($payments as $payment)
                                                <option value="{{ $payment['id'] }}">Веќе внесено: {{ $payment['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    @error("lines.$i.invoice_id") <span class="block text-red-600 text-xs">{{ $message }}</span> @enderror
                                @endif
                            </td>
                            <td class="py-1 pr-2">
                                <x-text-input type="date" wire:model="lines.{{ $i }}.line_date" class="w-full text-xs py-1" :disabled="$locked" />
                            </td>
                            <td class="py-1 pr-2">
                                <x-text-input inputmode="decimal" wire:model.live.debounce.300ms="lines.{{ $i }}.amount" class="w-full text-right text-xs py-1" :disabled="$locked" />
                                @error("lines.$i.amount") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </td>
                            <td class="py-1 pr-2">
                                <div class="flex gap-1">
                                    <x-text-input inputmode="decimal" wire:model.live.debounce.300ms="lines.{{ $i }}.exchange_rate" class="w-full text-right text-xs py-1" :disabled="$locked" />
                                    @unless ($locked)
                                        <button type="button" wire:click="fetchRate({{ $i }})" class="text-xs text-brand hover:underline whitespace-nowrap">НБРМ</button>
                                    @endunless
                                </div>
                                @error("lines.$i.exchange_rate") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </td>
                            <td class="py-1 pr-2 text-right text-xs text-gray-600">
                                @if (\App\Support\Bcmath::isPlainNumber((string) $line['amount']) && \App\Support\Bcmath::isPlainNumber((string) $line['exchange_rate']))
                                    <span class="block py-1">{{ \App\Support\Format::money(\App\Support\Bcmath::roundHalfUp(bcmul((string) $line['amount'], (string) $line['exchange_rate'], 10), 2), '') }}</span>
                                @endif
                            </td>
                            <td class="py-1 pr-2">
                                <textarea wire:model="lines.{{ $i }}.description" rows="2" @disabled($locked)
                                          class="w-full border-gray-300 rounded-md text-xs text-gray-600 leading-snug py-1"></textarea>
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

        @unless ($locked)
            <button type="button" wire:click="addLine" class="mt-3 text-brand text-sm hover:underline">+ Додај ставка</button>
        @endunless

        <p class="mt-2 text-xs text-gray-500">
            Наплата: Должи 1030 по курсот на денот на приливот, а побарувањето се затвора по курсот на фактурата (разликата оди на 4750/7750).
            Продажба на девиза: Должи 1039, Побарува 1030, по ист курс.
        </p>
    </x-card>

    <x-card class="mb-4">
        @php($diff = $this->difference)
        <p class="text-sm mb-2 flex flex-wrap gap-x-6 font-semibold text-gray-800">
            <span>Вкупно уплати: {{ \App\Support\Format::money($this->totals['in'], '') }} {{ $currency }}</span>
            <span>Вкупно продадена девиза: {{ \App\Support\Format::money($this->totals['out'], '') }} {{ $currency }}</span>
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
        @if ($locked)
            <x-secondary-button wire:click="reopen" wire:confirm="Отворањето на изводот го брише неговиот налог и наплатите што ги создал. Продолжи?">Отвори за измена</x-secondary-button>
        @else
            <x-secondary-button wire:click="save">Зачувај нацрт</x-secondary-button>
            <x-primary-button type="button" wire:click="post">Потврди и прокнижи</x-primary-button>
        @endif
        <a href="{{ route('bank-statements.index', $company) }}" class="text-sm text-gray-600 hover:underline">Назад</a>
    </div>
</div>
