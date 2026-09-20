@php
    // Едно место за ширините на колоните: го делат заглавието и секој ред,
    // за да не се разидат кога ќе се менува некоја колона.
    $grid = 'md:grid md:grid-cols-[minmax(10rem,1.5fr)_minmax(9rem,2fr)_4.5rem_7rem_7rem_5rem_7rem_7rem_7rem_10rem_2rem] md:gap-x-2 md:items-start';
    $cell = 'text-right tabular-nums';
    $label = 'block text-[11px] font-medium text-stone md:hidden';
@endphp

<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $salesInvoice ? 'Измени нацрт фактура' : 'Нова излезна фактура' }} — {{ $company->name }}
    </h1>

    @if ($this->canReadScans() && ! $salesInvoice)
        <x-card class="mb-4">
            <x-input-label for="scanFile" value="Прикачи скенирана фактура" />
            <p class="text-xs text-gray-500 mt-1 mb-2">PDF, JPG или PNG, до 10 МБ. Тами ќе ја прочита и ќе ги пополни полињата подолу.</p>
            <div class="flex items-center gap-3">
                <input id="scanFile" type="file" wire:model="scanFile" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" />
                <x-secondary-button type="button" wire:click="readScan" wire:loading.attr="disabled" wire:target="readScan,scanFile">
                    <span wire:loading.remove wire:target="readScan">Прочитај ја фактурата</span>
                    <span wire:loading wire:target="readScan">Читам…</span>
                </x-secondary-button>
            </div>
            @error('scanFile') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
        </x-card>
    @endif

    @if ($scanRead)
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Податоците се прочитани од скен — провери ги пред потврда.
            @foreach ($scanWarnings as $warning)
                <p class="mt-1 font-semibold">{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    @if ($suggestedPartner)
        <div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm">
            <p class="font-semibold text-blue-900">Купувачот од скенот го нема во шифрарникот.</p>
            <p class="mt-1 text-blue-900">
                {{ $suggestedPartner['name'] }} — ЕДБ {{ $suggestedPartner['tax_id'] }}
                @if ($suggestedPartner['city'])
                    , {{ $suggestedPartner['street_address'] }} {{ $suggestedPartner['street_number'] }}, {{ $suggestedPartner['postal_code'] }} {{ $suggestedPartner['city'] }}
                @endif
            </p>
            @foreach (['name', 'tax_id', 'street_address', 'street_number', 'postal_code', 'city'] as $suggestedPartnerField)
                @error("suggestedPartner.{$suggestedPartnerField}") <p class="mt-1 text-red-600">{{ $message }}</p> @enderror
            @endforeach
            <x-secondary-button type="button" wire:click="createSuggestedPartner" class="mt-2">Создај партнер</x-secondary-button>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-card class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="partnerId" value="Купувач" />
                <select id="partnerId" wire:model="partnerId" class="w-full border-gray-300 rounded-md text-sm">
                    <option value="">Изберете купувач</option>
                    @foreach ($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </select>
                @error('partnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="warehouseId" value="Магацин" />
                <select id="warehouseId" wire:model="warehouseId" @disabled(! $requiresWarehouse)
                    class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm disabled:bg-gray-50 disabled:text-gray-400">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-stone">
                    {{ $requiresWarehouse
                        ? 'Потребен е затоа што некоја ставка е артикл од залиха.'
                        : 'Се бара само ако некоја ставка е артикл од залиха. Услугите не поместуваат залиха.' }}
                </p>
                @error('warehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="invoiceDate" value="Датум на фактура" />
                <x-text-input id="invoiceDate" type="date" wire:model="invoiceDate" class="w-full" />
                @error('invoiceDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="dueDate" value="Датум на доспевање" />
                <x-text-input id="dueDate" type="date" wire:model="dueDate" class="w-full" />
                @error('dueDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
                @if ($paperNumber !== '' || ($scanRead ?? false))
                    <div>
                        <x-input-label for="paperNumber" value="Број од фактурата" />
                        <x-text-input id="paperNumber" type="text" wire:model="paperNumber" class="w-full" />
                        <p class="text-xs text-gray-500 mt-1">Бројот како што стои на хартијата. Празно значи дека Тами ќе издаде свој број.</p>
                        @error('paperNumber') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                @endif
            <div>
                <x-input-label for="paymentTypeCode" value="Начин на плаќање" />
                <select id="paymentTypeCode" wire:model="paymentTypeCode" class="w-full rounded-lg border-gray-300 text-sm">
                    @foreach (\App\Models\SalesInvoice::PAYMENT_TYPES as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('paymentTypeCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            @if ($company->type->isIndividual())
                <div>
                    <x-input-label for="currency" value="Валута" />
                    <select id="currency" wire:model.live="currency" class="w-full rounded-lg border-gray-300 text-sm">
                        @foreach (\App\Models\SalesInvoice::CURRENCIES as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                    @error('currency') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                @if ($currency !== 'MKD')
                    <div>
                        <x-input-label for="exchangeRate" value="Курс (1 {{ $currency }} = ? ден)" />
                        <div class="flex gap-2">
                            <x-text-input id="exchangeRate" wire:model="exchangeRate" class="w-full" />
                            <button type="button" wire:click="fetchRate" class="shrink-0 px-3 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                                НБРМ
                            </button>
                        </div>
                        @error('exchangeRate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                @endif
            @endif
        </x-card>

        <x-card padding="p-0" class="overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-sand">
                <h2 class="font-semibold text-gray-700">Ставки</h2>
                <button type="button" wire:click="addLine" class="text-brand text-sm font-medium hover:underline">+ Додади ставка</button>
            </div>

            <div class="overflow-x-auto">
                <div class="md:min-w-[76rem]">
                    <div class="{{ $grid }} hidden px-4 py-2 bg-paper-warm text-[11px] font-semibold uppercase tracking-wide text-stone border-b border-sand">
                        <div>Артикл</div>
                        <div>Опис</div>
                        <div class="text-right">Кол.</div>
                        <div class="text-right">Цена без ДДВ</div>
                        <div class="text-right">{{ $vatRegistered ? 'Цена со ДДВ' : '—' }}</div>
                        <div class="text-right">ДДВ %</div>
                        <div class="text-right">Износ ДДВ</div>
                        <div class="text-right">Вкупно без ДДВ</div>
                        <div class="text-right">Вкупно со ДДВ</div>
                        <div>Третман на ДДВ</div>
                        <div></div>
                    </div>

                    @foreach ($lines as $index => $line)
                        <div wire:key="line-{{ $index }}"
                            class="{{ $grid }} px-4 py-2 border-b border-sand/70 space-y-2 md:space-y-0 odd:bg-white even:bg-paper/60 hover:bg-orange-50/40">

                            <div>
                                <span class="{{ $label }}">Артикл</span>
                                <select wire:change="selectItem({{ $index }}, $event.target.value)"
                                    class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm py-1">
                                    <option value="">— слободен текст —</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>
                                            {{ $item->code }} — {{ $item->name }}@if ($item->isService()) (услуга) @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <span class="{{ $label }}">Опис</span>
                                <x-text-input wire:model="lines.{{ $index }}.description" class="w-full text-sm py-1" />
                                @error("lines.{$index}.description") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <span class="{{ $label }}">Количина</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" class="w-full text-sm py-1 {{ $cell }}" />
                                @error("lines.{$index}.quantity") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <span class="{{ $label }}">Цена без ДДВ</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price" class="w-full text-sm py-1 {{ $cell }}" />
                                @error("lines.{$index}.unit_price") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                @if ($vatRegistered)
                                    <span class="{{ $label }}">Цена со ДДВ</span>
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price_gross"
                                        class="w-full text-sm py-1 {{ $cell }}"
                                        @disabled($line['vat_treatment'] !== 'standard') />
                                @endif
                            </div>

                            <div>
                                <span class="{{ $label }}">ДДВ %</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.vat_rate"
                                    class="w-full text-sm py-1 {{ $cell }}"
                                    @disabled($line['vat_treatment'] !== 'standard') />
                            </div>

                            <div class="flex justify-between md:block md:pt-2">
                                <span class="{{ $label }}">Износ ДДВ</span>
                                <span class="block text-sm text-stone {{ $cell }}">{{ \App\Support\Format::money($rows[$index]['vat'], '') }}</span>
                            </div>

                            <div class="flex justify-between md:block md:pt-2">
                                <span class="{{ $label }}">Вкупно без ДДВ</span>
                                <span class="block text-sm text-stone {{ $cell }}">{{ \App\Support\Format::money($rows[$index]['net'], '') }}</span>
                            </div>

                            <div class="flex justify-between md:block md:pt-2">
                                <span class="{{ $label }}">Вкупно со ДДВ</span>
                                <span class="block text-sm font-semibold text-gray-800 {{ $cell }}">{{ \App\Support\Format::money($rows[$index]['gross'], '') }}</span>
                            </div>

                            <div>
                                <span class="{{ $label }}">Третман на ДДВ</span>
                                <select wire:change="setVatTreatment({{ $index }}, $event.target.value)"
                                    class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm py-1">
                                    <option value="standard" @selected($line['vat_treatment'] === 'standard')>Стандарден</option>
                                    <option value="export" @selected($line['vat_treatment'] === 'export')>Извоз</option>
                                    <option value="exempt_with_credit" @selected($line['vat_treatment'] === 'exempt_with_credit')>Ослободено (со право на одбивка)</option>
                                    <option value="exempt_without_credit" @selected($line['vat_treatment'] === 'exempt_without_credit')>Ослободено (без право на одбивка)</option>
                                </select>
                            </div>

                            <div class="md:pt-1 md:text-right">
                                <button type="button" wire:click="removeLine({{ $index }})"
                                    title="Отстрани ја ставката"
                                    class="text-sm text-stone hover:text-red-600">
                                    <span class="md:hidden">Отстрани ја ставката</span>
                                    <span class="hidden md:inline" aria-hidden="true">✕</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end px-4 py-3 bg-paper-warm">
                <dl class="w-full sm:w-72 text-sm space-y-1">
                    <div class="flex justify-between">
                        <dt class="text-stone">Основица</dt>
                        <dd class="tabular-nums">{{ \App\Support\Format::money($totals['net'], $moneyLabel) }}</dd>
                    </div>
                    @if ($vatRegistered)
                        <div class="flex justify-between">
                            <dt class="text-stone">ДДВ</dt>
                            <dd class="tabular-nums">{{ \App\Support\Format::money($totals['vat'], $moneyLabel) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-sand pt-1 font-semibold text-gray-800">
                        <dt>Вкупно</dt>
                        <dd class="tabular-nums">{{ \App\Support\Format::money($totals['gross'], $moneyLabel) }}</dd>
                    </div>
                </dl>
            </div>
        </x-card>

        <x-card>
            <x-input-label for="notes" value="Забелешки" />
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </x-card>

        <x-primary-button type="submit">Зачувај нацрт</x-primary-button>
    </form>
</div>
