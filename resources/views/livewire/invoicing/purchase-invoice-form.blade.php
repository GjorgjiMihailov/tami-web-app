@php
    // Едно место за ширините на колоните: го делат заглавието и секој ред,
    // за да не се разидат кога ќе се менува некоја колона.
    $grid = 'md:grid md:grid-cols-[minmax(11rem,2fr)_minmax(9rem,2fr)_4.5rem_7rem_7rem_5rem_7rem_7rem_7rem_4.5rem_2rem] md:gap-x-2 md:items-start';
    $cell = 'text-right tabular-nums';
    $label = 'block text-[11px] font-medium text-stone md:hidden';
@endphp

<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $purchaseInvoice ? 'Измени нацрт влезна фактура' : 'Нова влезна фактура' }} — {{ $company->name }}
    </h1>

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
                <div class="md:min-w-[72rem]">
                    <div class="{{ $grid }} hidden px-4 py-2 bg-paper-warm text-[11px] font-semibold uppercase tracking-wide text-stone border-b border-sand">
                        <div>Артикл / сметка</div>
                        <div>Опис</div>
                        <div class="text-right">Кол.</div>
                        <div class="text-right">Цена без ДДВ</div>
                        <div class="text-right">{{ $vatRegistered ? 'Цена со ДДВ' : '—' }}</div>
                        <div class="text-right">ДДВ %</div>
                        <div class="text-right">Износ ДДВ</div>
                        <div class="text-right">Вкупно без ДДВ</div>
                        <div class="text-right">Вкупно со ДДВ</div>
                        <div class="text-center">Одбивка</div>
                        <div></div>
                    </div>

                    @foreach ($lines as $index => $line)
                        <div wire:key="line-{{ $index }}"
                            class="{{ $grid }} px-4 py-2 border-b border-sand/70 space-y-2 md:space-y-0 odd:bg-white even:bg-paper/60 hover:bg-orange-50/40">

                            <div class="space-y-1">
                                @if (! empty($line['needs_review']))
                                    <x-badge status="pending" title="ДДВ стапката не можеше автоматски да се утврди — проверете рачно">⚠ Проверете ДДВ</x-badge>
                                @endif
                                <span class="{{ $label }}">Артикл / сметка</span>
                                <select wire:change="selectItem({{ $index }}, $event.target.value)"
                                    class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm py-1">
                                    <option value="">— без артикл —</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>
                                            {{ $item->code }} — {{ $item->name }}@if ($item->isService()) (услуга) @endif
                                        </option>
                                    @endforeach
                                </select>
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
                                <span class="{{ $label }}">Опис</span>
                                <x-text-input wire:model="lines.{{ $index }}.description" class="w-full text-sm py-1" />
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
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price_gross" class="w-full text-sm py-1 {{ $cell }}" />
                                @endif
                            </div>

                            <div>
                                <span class="{{ $label }}">ДДВ %</span>
                                <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.vat_rate" class="w-full text-sm py-1 {{ $cell }}" />
                                @error("lines.{$index}.vat_rate") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
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

                            <div class="flex items-center gap-2 md:justify-center md:pt-2">
                                <input type="checkbox" id="vatDeductible{{ $index }}" wire:model.live="lines.{{ $index }}.vat_deductible"
                                    class="rounded border-gray-300 text-brand focus:ring-brand">
                                <label for="vatDeductible{{ $index }}" class="text-[11px] text-stone md:sr-only">ДДВ за одбивка</label>
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
