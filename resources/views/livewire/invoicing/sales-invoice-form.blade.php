<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $salesInvoice ? 'Измени нацрт фактура' : 'Нова излезна фактура' }} — {{ $company->name }}
    </h1>

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
        </x-card>

        <x-card>
            <h2 class="font-semibold text-gray-700 mb-3">Ставки</h2>
            @foreach ($lines as $index => $line)
                <div class="flex flex-wrap gap-3 items-end mb-3 pb-3 border-b border-gray-100">
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
