<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $proforma ? 'Уреди профактура '.$proforma->proforma_number_formatted : 'Нова профактура' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save">
        <x-card class="mb-4">
            <div class="grid gap-3 md:grid-cols-[12rem_1fr] items-center max-w-3xl">
                <label for="partnerId" class="text-sm text-red-600">Купувач *</label>
                <div>
                    <select id="partnerId" wire:model.live="partnerId" class="border-gray-300 rounded-md text-sm w-full max-w-md">
                        <option value="">Избери кооперант</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                    @error('partnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    <a href="{{ route('partners.create', $company) }}" wire:navigate class="ml-2 text-brand text-sm hover:underline">+ Нов кооперант</a>
                </div>

                <span class="text-sm text-gray-700">Број на профактура</span>
                <span class="text-sm font-mono text-gray-800">{{ $proforma ? $proforma->proforma_number_formatted : 'Се доделува при зачувување (според поставките за фактурирање)' }}</span>

                <label for="reference" class="text-sm text-gray-700">Референца</label>
                <x-text-input id="reference" wire:model="reference" class="w-full max-w-sm" />

                <label for="proformaDate" class="text-sm text-red-600">Датум на профактура *</label>
                <div>
                    <x-text-input id="proformaDate" type="date" wire:model="proformaDate" class="w-48" />
                    @error('proformaDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <label for="expectedDeliveryDate" class="text-sm text-gray-700">Очекуван датум на испорака</label>
                <div>
                    <x-text-input id="expectedDeliveryDate" type="date" wire:model="expectedDeliveryDate" class="w-48" />
                    @error('expectedDeliveryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <label for="paymentTermsDays" class="text-sm text-gray-700">Рок на плаќање</label>
                <select id="paymentTermsDays" wire:model="paymentTermsDays" class="border-gray-300 rounded-md text-sm w-48">
                    <option value="">Не е одредено</option>
                    @foreach (\App\Models\Partner::PAYMENT_TERMS as $days)
                        <option value="{{ $days }}">{{ $days === 0 ? 'По приемот' : "{$days} дена" }}</option>
                    @endforeach
                </select>

                @if ($company->type->isIndividual())
                    <label for="currency" class="text-sm text-gray-700">Валута</label>
                    <select id="currency" wire:model.live="currency" class="border-gray-300 rounded-md text-sm w-48">
                        @foreach (\App\Models\SalesInvoice::CURRENCIES as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </x-card>

        <x-card class="mb-4" padding="p-0">
            <div class="px-4 py-3 border-b border-sand font-semibold text-gray-700">Ставки</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">Артикл / опис</th>
                            <th class="py-1 px-3 text-right">Количина</th>
                            <th class="py-1 px-3 text-right">Цена (нето)</th>
                            @if ($vatRegistered) <th class="py-1 px-3 text-right">ДДВ %</th> @endif
                            <th class="py-1 px-3 text-right">Износ</th>
                            <th class="py-1 px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($lines as $index => $line)
                            <tr wire:key="line-{{ $index }}">
                                <td class="py-1 px-3 min-w-[16rem]">
                                    <select wire:change="selectItem({{ $index }}, $event.target.value)" aria-label="Артикл" class="border-gray-300 rounded-md text-sm w-full mb-1">
                                        <option value="">Избери артикл (по избор)</option>
                                        @foreach ($items as $item)
                                            <option value="{{ $item->id }}" @selected((string) $line['item_id'] === (string) $item->id)>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-text-input wire:model="lines.{{ $index }}.description" placeholder="Опис" aria-label="Опис" class="w-full" />
                                    @error("lines.{$index}.description") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                                </td>
                                <td class="py-1 px-3 text-right align-top">
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" aria-label="Количина" class="w-24 text-right" />
                                    @error("lines.{$index}.quantity") <span class="block text-red-600 text-xs">{{ $message }}</span> @enderror
                                </td>
                                <td class="py-1 px-3 text-right align-top">
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price" aria-label="Цена" class="w-28 text-right" />
                                    @error("lines.{$index}.unit_price") <span class="block text-red-600 text-xs">{{ $message }}</span> @enderror
                                </td>
                                @if ($vatRegistered)
                                    <td class="py-1 px-3 text-right align-top">
                                        <x-text-input wire:model.live.debounce.400ms="lines.{{ $index }}.vat_rate" aria-label="ДДВ" class="w-20 text-right" />
                                    </td>
                                @endif
                                <td class="py-1 px-3 text-right align-top whitespace-nowrap pt-3">{{ \App\Support\Format::money($rows[$index]['net'], $moneyLabel) }}</td>
                                <td class="py-1 px-3 align-top pt-3">
                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-gray-400 hover:text-red-600" aria-label="Избриши ставка">×</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-sand">
                <button type="button" wire:click="addLine" class="text-brand text-sm hover:underline">+ Додади ставка</button>
                @error('lines') <span class="ml-3 text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </x-card>

        <div class="grid gap-4 lg:grid-cols-2 mb-4">
            <x-card>
                <label for="notes" class="text-sm text-gray-700">Белешка за купувачот</label>
                <textarea id="notes" wire:model="notes" rows="3" placeholder="Се печати на профактурата" class="border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm w-full text-sm mb-3"></textarea>
                <label for="terms" class="text-sm text-gray-700">Услови</label>
                <textarea id="terms" wire:model="terms" rows="3" placeholder="Услови на вашето работење, се печатат на профактурата" class="border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm w-full text-sm"></textarea>
            </x-card>

            <x-card class="bg-gray-50">
                <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
                    <dt class="text-gray-600">Вкупно без ДДВ</dt><dd class="text-right">{{ \App\Support\Format::money($totals['net'], $moneyLabel) }}</dd>
                    @if ($vatRegistered)
                        <dt class="text-gray-600">ДДВ</dt><dd class="text-right">{{ \App\Support\Format::money($totals['vat'], $moneyLabel) }}</dd>
                    @endif
                    <dt class="font-semibold text-gray-800 border-t border-sand pt-2">Вкупно ({{ $currency }})</dt>
                    <dd class="text-right font-semibold text-gray-800 border-t border-sand pt-2">{{ \App\Support\Format::money($totals['gross'], $moneyLabel) }}</dd>
                </dl>
            </x-card>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if (! $proforma || $proforma->status === 'draft')
                <x-secondary-button type="button" wire:click="saveDraft">Зачувај како нацрт</x-secondary-button>
            @endif
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ $proforma ? route('proformas.index', [$company, 'proforma' => $proforma->id]) : route('proformas.index', $company) }}" wire:navigate class="text-gray-500 text-sm hover:underline">Откажи</a>
        </div>
    </form>
</div>
