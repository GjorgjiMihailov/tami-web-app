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
                <div class="w-64">
                    <x-input-label value="Партнер" />
                    <select wire:model.live="lines.{{ $i }}.partner_id" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
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
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1 w-6">#</th>
                    <th class="py-1 w-24">Ознака</th>
                    <th class="py-1 w-36">Датум</th>
                    <th class="py-1 w-28">Вид</th>
                    <th class="py-1 w-32 text-right">Износ</th>
                    <th class="py-1 w-48">Партнер</th>
                    <th class="py-1 w-40">Намена</th>
                    <th class="py-1">Конто / фактура</th>
                    <th class="py-1">Опис</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $i => $line)
                    <tr wire:key="line-{{ $i }}">
                        <td class="py-1">{{ $i + 1 }}</td>
                        <td class="py-1">
                            @php($saved = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
                            @if ($saved?->match_confidence)
                                @php($confidence = $saved->match_confidence)
                                <span class="px-2 py-0.5 rounded text-xs {{ ['sure' => 'bg-green-100 text-green-800', 'check' => 'bg-amber-100 text-amber-800', 'unclear' => 'bg-red-100 text-red-800'][$confidence->value] }}"
                                      title="{{ $saved->match_reason }}">{{ $confidence->label() }}</span>
                                @if ($confidence->needsReview() && ! empty($line['reviewed']))
                                    <span class="block text-xs text-green-700">✓ проверено</span>
                                @endif
                            @endif
                        </td>
                        <td><x-text-input type="date" wire:model="lines.{{ $i }}.line_date" class="w-full" :disabled="$statement->isBooked()" /></td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.direction" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                @foreach (\App\Support\Bank\LineDirection::cases() as $direction)
                                    <option value="{{ $direction->value }}">{{ $direction->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <x-text-input wire:model.live.debounce.300ms="lines.{{ $i }}.amount" class="w-full text-right" :disabled="$statement->isBooked()" />
                            @error("lines.$i.amount") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                        </td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.partner_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                <option value="">—</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.kind" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                @foreach (\App\Support\Bank\LineKind::cases() as $kind)
                                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            @if ($line['kind'] === 'invoice_payment')
                                <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                    <option value="">— фактура —</option>
                                    @foreach ($this->invoiceOptions($i) as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @if (! empty($line['invoice_id']))
                                    @php($payments = $this->paymentOptions($i))
                                    @if ($payments !== [])
                                        <select wire:model.live="lines.{{ $i }}.existing_payment_id" class="mt-1 border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                            <option value="">— ново плаќање —</option>
                                            @foreach ($payments as $payment)
                                                <option value="{{ $payment['id'] }}">Веќе внесено: {{ $payment['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                @endif
                            @else
                                <select wire:model="lines.{{ $i }}.account_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                    <option value="">— конто —</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td>
                            <x-text-input wire:model="lines.{{ $i }}.description" class="w-full" :disabled="$statement->isBooked()" />
                            @php($meta = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
                            @if ($meta !== null && ($meta->counterparty_name || $meta->counterparty_account || $meta->reference_number))
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $meta->counterparty_name }}
                                    @if ($meta->counterparty_account) · {{ $meta->counterparty_account }} @endif
                                    @if ($meta->reference_number) · реф. {{ $meta->reference_number }} @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            @unless ($statement->isBooked())
                                <button type="button" wire:click="removeLine({{ $i }})" class="text-red-600 text-sm">Бриши</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @unless ($statement->isBooked())
            <button type="button" wire:click="addLine" class="mt-3 text-brand text-sm hover:underline">+ Додај ставка</button>
        @endunless
    </x-card>

    <x-card class="mb-4">
        @php($diff = $this->difference)
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
    </div>
</div>
