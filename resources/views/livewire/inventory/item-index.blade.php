<div>
    <div class="flex flex-wrap items-center gap-4 mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Артикли — {{ $company->name }}</h1>
        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
            Масовен внес преку табела
        </a>
    </div>

    <div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
        {{-- Лева страна: список --}}
        <x-card padding="p-0" class="overflow-hidden {{ $selected ? 'hidden lg:block' : '' }}">
            <div class="p-3 border-b border-sand flex items-center gap-2">
                <select wire:model.live="filter" aria-label="Филтер" class="border-gray-300 rounded-md text-sm font-semibold flex-1">
                    <option value="active">Активни артикли</option>
                    <option value="inactive">Неактивни артикли</option>
                    <option value="all">Сите артикли</option>
                    <option value="product">Производи</option>
                    <option value="service">Услуги</option>
                </select>
                @can('create', \App\Models\Item::class)
                    <a href="{{ route('inventory.items.create', $company) }}" wire:navigate
                       class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand text-white text-xl leading-none" title="Нов артикл" aria-label="Нов артикл">+</a>
                @endcan
            </div>
            <div class="p-3 border-b border-sand">
                <x-text-input wire:model.live="search" placeholder="Пребарувај по назив или шифра" class="w-full" />
            </div>

            <ul class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
                @forelse ($items as $item)
                    <li wire:key="item-{{ $item->id }}">
                        <button type="button" wire:click="select({{ $item->id }})"
                                class="w-full text-left px-4 py-2 flex items-baseline justify-between gap-3 hover:bg-orange-50 {{ $selected?->id === $item->id ? 'bg-orange-50' : '' }} {{ $item->is_active ? '' : 'text-gray-400' }}">
                            <span class="min-w-0">
                                <span class="block text-sm truncate">{{ $item->name }}</span>
                                <span class="block text-xs text-gray-500 font-mono">{{ $item->code }}</span>
                            </span>
                            <span class="text-sm whitespace-nowrap">
                                {{ $item->selling_price !== null ? \App\Support\Format::money($item->selling_price) : '—' }}
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-4 text-sm text-gray-500">Нема артикли.</li>
                @endforelse
            </ul>
        </x-card>

        {{-- Десна страна: детали --}}
        <div class="{{ $selected ? '' : 'hidden lg:block' }}">
            @if ($selected)
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-800">{{ $selected->name }}</h2>
                            <p class="text-sm text-gray-500">
                                {{ \App\Support\Format::itemType($selected->type) }}
                                @unless ($selected->is_active) · Неактивен @endunless
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            @can('update', $selected)
                                <a href="{{ route('inventory.items.edit', [$company, $selected]) }}" wire:navigate class="text-brand hover:underline text-sm">Уреди</a>
                                <button type="button" wire:click="toggleActive({{ $selected->id }})" class="text-brand hover:underline text-sm">
                                    {{ $selected->is_active ? 'Деактивирај' : 'Активирај' }}
                                </button>
                            @endcan
                            <button type="button" wire:click="closeDetail" class="text-gray-500 hover:text-gray-700 text-xl leading-none lg:hidden" aria-label="Назад">×</button>
                        </div>
                    </div>

                    <nav class="flex gap-1 border-b border-sand mb-4">
                        @foreach (['overview' => 'Преглед', 'transactions' => 'Трансакции'] as $key => $label)
                            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                                    class="px-4 py-2 text-sm font-medium rounded-t-lg {{ $tab === $key ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </nav>

                    @if ($tab === 'overview')
                        @php
                            $rows = fn (array $pairs) => collect($pairs)->filter(fn ($v) => $v !== null && $v !== '');
                        @endphp

                        <h3 class="font-semibold text-gray-700 mb-2">Основни податоци</h3>
                        <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-sm mb-6">
                            @foreach ($rows([
                                'Шифра' => $selected->code,
                                'Тип' => \App\Support\Format::itemType($selected->type),
                                'Категорија' => $selected->category,
                                'Мерна единица' => $selected->unit_of_measure,
                                'Баркод' => $selected->barcode,
                                'МК-производство' => $selected->is_made_in_mk ? 'Да' : 'Не',
                                'Опис' => $selected->description,
                            ]) as $label => $value)
                                <dt class="text-gray-500">{{ $label }}</dt>
                                <dd class="text-gray-800 whitespace-pre-line">{{ $value }}</dd>
                            @endforeach
                        </dl>

                        <h3 class="font-semibold text-gray-700 mb-2">Продажба</h3>
                        @if ($selected->is_sellable)
                            <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-sm mb-6">
                                <dt class="text-gray-500">Продажна цена</dt>
                                <dd>{{ $selected->selling_price !== null ? \App\Support\Format::money($selected->selling_price) : '—' }}</dd>
                                <dt class="text-gray-500">ДДВ</dt>
                                <dd>{{ \App\Support\Format::rate($selected->vat_rate) }} %</dd>
                            </dl>
                        @else
                            <p class="text-sm text-gray-500 mb-6">Не се продава.</p>
                        @endif

                        <h3 class="font-semibold text-gray-700 mb-2">Набавка</h3>
                        @if ($selected->is_purchasable)
                            <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-sm mb-6">
                                <dt class="text-gray-500">Набавна цена</dt>
                                <dd>{{ $selected->cost_price !== null ? \App\Support\Format::money($selected->cost_price) : '—' }}</dd>
                                <dt class="text-gray-500">ДДВ</dt>
                                <dd>{{ \App\Support\Format::rate($selected->purchaseVatRate()) }} %</dd>
                                @if ($selected->preferredPartner)
                                    <dt class="text-gray-500">Основен добавувач</dt>
                                    <dd>{{ $selected->preferredPartner->name }}</dd>
                                @endif
                            </dl>
                        @else
                            <p class="text-sm text-gray-500 mb-6">Не се набавува.</p>
                        @endif

                        @unless ($selected->isService())
                            <h3 class="font-semibold text-gray-700 mb-2">Залиха</h3>
                            @if ($stock->isEmpty())
                                <p class="text-sm text-gray-500 mb-6">Нема залиха.</p>
                            @else
                                <table class="text-sm mb-6 w-full max-w-md">
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($stock as $row)
                                            <tr>
                                                <td class="py-1">{{ $row['warehouse'] }}</td>
                                                <td class="py-1 text-right">{{ rtrim(rtrim(number_format($row['quantity'], 3, ',', '.'), '0'), ',') }} {{ $selected->unit_of_measure }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        @endunless

                        <div class="rounded-2xl border border-sand">
                            <div class="flex items-center justify-between px-4 py-3 border-b border-sand">
                                <h3 class="font-semibold text-gray-700">Продажба (без ДДВ, во ден)</h3>
                                <select wire:model.live="period" aria-label="Период" class="border-gray-300 rounded-md text-sm">
                                    <option value="this_month">Овој месец</option>
                                    <option value="last_month">Минатиот месец</option>
                                    <option value="this_year">Оваа година</option>
                                </select>
                            </div>
                            <div class="p-4">
                                @if (empty($sales['days']))
                                    <p class="text-sm text-gray-500">Нема продажба во овој период.</p>
                                @else
                                    @php $max = max(array_column($sales['days'], 'amount')) ?: 1; @endphp
                                    <div class="flex items-end gap-1 h-32 mb-1" role="img" aria-label="Продажба по {{ $period === 'this_year' ? 'месец' : 'ден' }}">
                                        @foreach ($sales['days'] as $day)
                                            <div class="flex-1 bg-brand rounded-t min-h-[2px]"
                                                 style="height: {{ max(2, round($day['amount'] / $max * 100)) }}%"
                                                 title="{{ \App\Support\Format::date($day['date']) }}: {{ \App\Support\Format::money($day['amount']) }}"></div>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="text-sm mt-3">
                                    <span class="text-gray-500">Вкупно:</span>
                                    <strong>{{ \App\Support\Format::money($sales['total']) }}</strong>
                                    <span class="text-gray-500 ml-3">Количина:</span>
                                    <strong>{{ rtrim(rtrim(number_format($sales['quantity'], 3, ',', '.'), '0'), ',') }} {{ $selected->unit_of_measure }}</strong>
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="mb-3">
                            <select wire:model.live="transactionFilter" aria-label="Вид на трансакција" class="border-gray-300 rounded-md text-sm">
                                <option value="all">Сите трансакции</option>
                                <option value="sales">Излезни фактури</option>
                                <option value="purchases">Влезни фактури</option>
                                <option value="stock">Движења на залиха</option>
                            </select>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                                    <th class="py-1 px-3">Датум</th>
                                    <th class="py-1 px-3">Вид</th>
                                    <th class="py-1 px-3">Документ</th>
                                    <th class="py-1 px-3">Партнер / магацин</th>
                                    <th class="py-1 px-3 text-right">Количина</th>
                                    <th class="py-1 px-3 text-right">Износ (без ДДВ)</th>
                                    <th class="py-1 px-3">Статус</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($transactions as $row)
                                    <tr class="text-sm hover:bg-orange-50">
                                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($row['date']) }}</td>
                                        <td class="py-1 px-3">{{ $row['kind'] }}</td>
                                        <td class="py-1 px-3">
                                            @if ($row['route'])
                                                <a href="{{ route($row['route'][0], $row['route'][1]) }}" wire:navigate class="text-brand hover:underline">{{ $row['document'] }}</a>
                                            @else
                                                {{ $row['document'] }}
                                            @endif
                                        </td>
                                        <td class="py-1 px-3">{{ $row['partner'] }}</td>
                                        <td class="py-1 px-3 text-right">{{ rtrim(rtrim(number_format($row['quantity'], 3, ',', '.'), '0'), ',') }}</td>
                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['amount'], $row['currency'] === 'MKD' ? 'ден' : $row['currency']) }}</td>
                                        <td class="py-1 px-3">{{ $row['status'] !== '' ? \App\Support\Format::invoiceStatus($row['status']) : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="py-4 px-3 text-sm text-gray-500">Нема трансакции.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </x-card>
            @else
                <x-card>
                    <p class="text-sm text-gray-500">Избери артикл од листата за да ги видиш деталите.</p>
                </x-card>
            @endif
        </div>
    </div>
</div>
