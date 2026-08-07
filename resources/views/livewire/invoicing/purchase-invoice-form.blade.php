<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $purchaseInvoice ? 'Измени нацрт влезна фактура' : 'Нова влезна фактура' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="space-y-6">
        <x-card class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="partnerId" value="Добавувач" />
                <select id="partnerId" wire:model="partnerId" class="w-full border-gray-300 rounded-md text-sm">
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
                    @if (! empty($line['needs_review']))
                        <div class="w-full">
                            <x-badge status="pending" title="ДДВ стапката не можеше автоматски да се утврди — проверете рачно">⚠ Проверете ДДВ</x-badge>
                        </div>
                    @endif
                    <div class="w-48">
                        <x-input-label value="Артикл (опционално)" />
                        <select wire:change="selectItem({{ $index }}, $event.target.value)" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="">— трошок/услуга —</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>{{ $item->code }} — {{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if (($line['item_id'] ?? '') === '')
                        <div class="w-56">
                            <x-input-label value="Сметка за трошок" />
                            <select wire:model="lines.{{ $index }}.account_id" class="w-full border-gray-300 rounded-md text-sm">
                                <option value="">Изберете сметка</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error("lines.{$index}.account_id") <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                    @endif
                    <div class="flex-1 min-w-[12rem]">
                        <x-input-label value="Опис" />
                        <x-text-input wire:model="lines.{{ $index }}.description" class="w-full" />
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
                        <x-text-input wire:model="lines.{{ $index }}.vat_rate" class="w-full" />
                    </div>
                    <div class="flex items-center gap-1 pb-2">
                        <input type="checkbox" id="vatDeductible{{ $index }}" wire:model="lines.{{ $index }}.vat_deductible">
                        <label for="vatDeductible{{ $index }}" class="text-xs">ДДВ за одбивка</label>
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
