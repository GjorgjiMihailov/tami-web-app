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
                <x-input-label for="warehouseId" value="Магацин (доколку некоја ставка содржи артикл)" />
                <select id="warehouseId" wire:model="warehouseId" class="w-full border-gray-300 rounded-md text-sm">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
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

        <x-card>
            <h2 class="font-semibold text-gray-700 mb-3">Ставки</h2>
            @foreach ($lines as $index => $line)
                <div class="flex flex-wrap gap-3 items-end mb-3 pb-3 border-b border-sand">
                    <div class="w-48">
                        <x-input-label value="Артикл (опционално)" />
                        <select wire:change="selectItem({{ $index }}, $event.target.value)" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="">— слободен текст —</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>{{ $item->code }} — {{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-[12rem]">
                        <x-input-label value="Опис" />
                        <x-text-input wire:model="lines.{{ $index }}.description" class="w-full" />
                        @error("lines.{$index}.description") <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div class="w-24">
                        <x-input-label value="Кол." />
                        <x-text-input wire:model="lines.{{ $index }}.quantity" class="w-full" />
                    </div>
                    <div class="w-32">
                        <x-input-label value="Ед. цена" />
                        <x-text-input wire:model="lines.{{ $index }}.unit_price" class="w-full" />
                    </div>
                    <div class="w-24">
                        <x-input-label value="ДДВ %" />
                        <x-text-input wire:model="lines.{{ $index }}.vat_rate" class="w-full" @disabled($line['vat_treatment'] !== 'standard') />
                    </div>
                    <div class="w-40">
                        <x-input-label value="Третман на ДДВ" />
                        <select wire:change="setVatTreatment({{ $index }}, $event.target.value)" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="standard" @selected($line['vat_treatment'] === 'standard')>Стандарден</option>
                            <option value="export" @selected($line['vat_treatment'] === 'export')>Извоз</option>
                            <option value="exempt_with_credit" @selected($line['vat_treatment'] === 'exempt_with_credit')>Ослободено (со право на одбивка)</option>
                            <option value="exempt_without_credit" @selected($line['vat_treatment'] === 'exempt_without_credit')>Ослободено (без право на одбивка)</option>
                        </select>
                    </div>
                    <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">Отстрани</button>
                </div>
            @endforeach

            <button type="button" wire:click="addLine" class="text-brand text-sm hover:underline">+ Додади ставка</button>
        </x-card>

        <x-card>
            <x-input-label for="notes" value="Забелешки" />
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </x-card>

        <x-primary-button type="submit">Зачувај нацрт</x-primary-button>
    </form>
</div>
