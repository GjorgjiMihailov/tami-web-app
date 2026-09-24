@php
    // Едно место за ширините на колоните: го делат заглавието и секој ред,
    // за да не се разидат кога ќе се менува некоја колона.
    //
    // Имињата се долги намерно. Blade не ја затвора променливата на `@foreach`,
    // па кратко име како `$label` го презапишува некоја јамка подолу во истиот
    // фајл и ознаките излегуваат со class="последната вредност од јамката".
    $lineGridClass = 'md:grid md:grid-cols-[minmax(10rem,1.4fr)_minmax(8rem,1.8fr)_4rem_6.5rem_6.5rem_4.5rem_6.5rem_6.5rem_6.5rem_4rem_2rem] md:gap-x-2 md:items-start';
    $lineNumberClass = 'text-right tabular-nums';
    $lineLabelClass = 'block text-[11px] font-medium text-stone md:hidden';
    // Почеток на групата „за ставката" — тенка линија што му помага на окото
    // да ги најде збировите меѓу единечните цени.
    $lineGroupEdge = 'md:border-l md:border-sand md:pl-2';
    $lineDerivedClass = 'block text-sm md:pt-1.5 '.$lineNumberClass;
@endphp

<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $purchaseInvoice ? 'Измени нацрт влезна фактура' : 'Нова влезна фактура' }} — {{ $company->name }}
    </h1>

    @if ($this->canReadScans() && ! $purchaseInvoice)
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
            Податоците се прочитани од скен — провери ги пред зачувување. Ставките што ги нема во Артикли
            имаат копче „Внеси како артикл".
            @foreach ($scanWarnings as $warning)
                <p class="mt-1 font-semibold">{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    @if ($suggestedPartner)
        <div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm">
            <p class="font-semibold text-blue-900">Добавувачот од скенот го нема во шифрарникот.</p>
            <p class="mt-1 text-blue-900">{{ $suggestedPartner['name'] }} — ЕДБ {{ $suggestedPartner['tax_id'] }}</p>
            @error('suggestedPartner.name') <p class="mt-1 text-red-600">{{ $message }}</p> @enderror
            @error('suggestedPartner.tax_id') <p class="mt-1 text-red-600">{{ $message }}</p> @enderror
            <x-secondary-button type="button" wire:click="createSuggestedPartner" class="mt-2">Создај партнер</x-secondary-button>
        </div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <x-card>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-x-4 gap-y-3">
                <div class="sm:col-span-2 xl:col-span-1">
                    <x-input-label for="partnerId" value="Добавувач" />
                    <select id="partnerId" wire:model="partnerId" class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm">
                        <option value="">Изберете добавувач</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                    @error('partnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="supplierInvoiceNumber" value="Број на фактура од добавувач" />
                    <x-text-input id="supplierInvoiceNumber" wire:model="supplierInvoiceNumber" class="w-full" />
                    @error('supplierInvoiceNumber') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
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
                            : 'Се бара само ако некоја ставка е артикл од залиха. Услугите не примаат залиха.' }}
                    </p>
                    @error('warehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <x-card padding="p-0" class="overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-sand">
                <h2 class="font-semibold text-gray-700">Ставки</h2>
                <button type="button" wire:click="addLine" class="text-brand text-sm font-medium hover:underline">+ Додади ставка</button>
            </div>

            <div class="overflow-x-auto">
                <div class="md:min-w-[68rem]">
                    <div class="{{ $lineGridClass }} hidden px-4 pt-2 text-[10px] font-semibold uppercase tracking-wider text-stone/70">
                        <div class="md:col-span-3"></div>
                        <div class="md:col-span-3 text-center">По единица</div>
                        <div class="md:col-span-3 text-center {{ $lineGroupEdge }}">За ставката</div>
                        <div class="md:col-span-2"></div>
                    </div>

                    <div class="{{ $lineGridClass }} hidden px-4 pb-2 text-[11px] font-semibold uppercase tracking-wide text-stone border-b border-sand">
                        <div>Артикл / сметка</div>
                        <div>Опис</div>
                        <div class="text-right">Кол.</div>
                        <div class="text-right">Без ДДВ</div>
                        <div class="text-right">{{ $vatRegistered ? 'Со ДДВ' : '—' }}</div>
                        <div class="text-right">ДДВ %</div>
                        <div class="text-right {{ $lineGroupEdge }}">ДДВ</div>
                        <div class="text-right">Без ДДВ</div>
                        <div class="text-right">Со ДДВ</div>
                        <div class="text-center">Одбивка</div>
                        <div></div>
                    </div>

                    @foreach ($lines as $index => $line)
                        <div wire:key="line-{{ $index }}"
                            class="{{ $lineGridClass }} px-4 py-2 border-b border-sand/70 space-y-2 md:space-y-0 odd:bg-white even:bg-paper/60 hover:bg-orange-50/40">

                            <div class="space-y-1">
                                @if (! empty($line['needs_review']))
                                    <x-badge status="pending" title="ДДВ стапката не можеше автоматски да се утврди — проверете рачно">⚠ Проверете ДДВ</x-badge>
                                @endif
                                <span class="{{ $lineLabelClass }}">Артикл / сметка</span>
                                <select wire:change="selectItem({{ $index }}, $event.target.value)"
                                    class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm py-1">
                                    <option value="">— без артикл —</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>
                                            {{ $item->code }} — {{ $item->name }}@if ($item->isService()) (услуга) @endif
                                        </option>
                                    @endforeach
                                </select>
                                @if (($line['item_id'] ?? '') === '' && trim((string) ($line['description'] ?? '')) !== '')
                                    <button type="button" wire:click="addLineAsItem({{ $index }})"
                                        class="text-xs text-brand font-medium hover:underline">+ Внеси како артикл (залиха)</button>
                                    @error("lines.{$index}.description") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                                @endif
                                @unless ($rows[$index]['is_stock'])
                                    <select wire:model="lines.{{ $index }}.account_id"
                                        class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm py-1">
                                        <option value="">Изберете сметка за трошок</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$index}.account_id") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                                @endunless
                            </div>

                            <div>
                                <span class="{{ $lineLabelClass }}">Опис</span>
                                <x-text-input wire:model="lines.{{ $index }}.description" class="w-full text-sm py-1" />
                            </div>

                            <div>
                                <span class="{{ $lineLabelClass }}">Количина</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" class="w-full text-sm py-1 {{ $lineNumberClass }}" />
                                @error("lines.{$index}.quantity") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <span class="{{ $lineLabelClass }}">Цена без ДДВ</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price" class="w-full text-sm py-1 {{ $lineNumberClass }}" />
                                @error("lines.{$index}.unit_price") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                @if ($vatRegistered)
                                    <span class="{{ $lineLabelClass }}">Цена со ДДВ</span>
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price_gross" class="w-full text-sm py-1 {{ $lineNumberClass }}" />
                                @endif
                            </div>

                            <div>
                                <span class="{{ $lineLabelClass }}">ДДВ %</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.vat_rate" class="w-full text-sm py-1 {{ $lineNumberClass }}" />
                                @error("lines.{$index}.vat_rate") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex justify-between md:block {{ $lineGroupEdge }}">
                                <span class="{{ $lineLabelClass }}">Износ ДДВ</span>
                                <span class="text-stone {{ $lineDerivedClass }}">{{ \App\Support\Format::money($rows[$index]['vat'], '') }}</span>
                            </div>

                            <div class="flex justify-between md:block">
                                <span class="{{ $lineLabelClass }}">Вкупно без ДДВ</span>
                                <span class="text-stone {{ $lineDerivedClass }}">{{ \App\Support\Format::money($rows[$index]['net'], '') }}</span>
                            </div>

                            <div class="flex justify-between md:block">
                                <span class="{{ $lineLabelClass }}">Вкупно со ДДВ</span>
                                <span class="font-semibold text-gray-800 {{ $lineDerivedClass }}">{{ \App\Support\Format::money($rows[$index]['gross'], '') }}</span>
                            </div>

                            <div class="flex items-center gap-2 md:justify-center md:pt-2">
                                <input type="checkbox" id="vatDeductible{{ $index }}" wire:model.live="lines.{{ $index }}.vat_deductible"
                                    class="rounded border-gray-300 text-brand focus:ring-brand">
                                <label for="vatDeductible{{ $index }}" class="text-[11px] text-stone md:sr-only">ДДВ за одбивка</label>
                            </div>

                            <div class="md:pt-1.5 md:text-right">
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
                        <dd class="tabular-nums">{{ \App\Support\Format::money($totals['net']) }}</dd>
                    </div>
                    @if ($vatRegistered)
                        <div class="flex justify-between">
                            <dt class="text-stone">ДДВ</dt>
                            <dd class="tabular-nums">{{ \App\Support\Format::money($totals['vat']) }}</dd>
                        </div>
                        @if (bccomp($totals['non_deductible_vat'], '0', 2) > 0)
                            <div class="flex justify-between text-stone">
                                <dt title="Овој дел од ДДВ-то не оди во претходен данок, туку влегува во трошокот.">
                                    од тоа без право на одбивка
                                </dt>
                                <dd class="tabular-nums">{{ \App\Support\Format::money($totals['non_deductible_vat']) }}</dd>
                            </div>
                        @endif
                    @endif
                    <div class="flex justify-between border-t border-sand pt-1 font-semibold text-gray-800">
                        <dt>Вкупно</dt>
                        <dd class="tabular-nums">{{ \App\Support\Format::money($totals['gross']) }}</dd>
                    </div>
                </dl>
            </div>
        </x-card>

        <x-card>
            <x-input-label for="notes" value="Забелешки" />
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm"></textarea>
        </x-card>

        <div class="flex items-center gap-3">
            <x-primary-button type="submit">Зачувај нацрт</x-primary-button>
            <span wire:loading class="text-sm text-stone">Пресметувам…</span>
        </div>
    </form>
</div>
