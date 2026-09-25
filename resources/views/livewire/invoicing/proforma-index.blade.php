<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Профактури — {{ $company->name }}</h1>

    @if (! $hasProformas)
        <x-card class="py-14">
            <div class="text-center">
                <h2 class="text-xl font-semibold text-gray-800">Профактурите ги отвораат продажбите</h2>
                <p class="mt-1 text-sm text-gray-500">Создади профактура, испрати ја на купувачот, а кога ќе ја прифати — претвори ја во фактура со еден клик.</p>
                @can('create', \App\Models\ProformaInvoice::class)
                    <a href="{{ route('proformas.create', $company) }}" wire:navigate class="mt-6 inline-block">
                        <x-primary-button type="button">+ Создади профактура</x-primary-button>
                    </a>
                @endcan
            </div>

            <div class="mt-10 flex flex-wrap items-center justify-center gap-2 text-sm">
                @foreach (['Профактура', 'Потврдена', 'Претворена во фактура', 'Наплатена'] as $step)
                    <span class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sky-800">{{ $step }}</span>
                    @unless ($loop->last) <span class="text-gray-400" aria-hidden="true">→</span> @endunless
                @endforeach
            </div>

            <ul class="mt-8 mx-auto max-w-xl space-y-2 text-sm text-gray-700">
                <li>✓ Направи профактура со артикли и цени, со свој број од серијата на фирмата.</li>
                <li>✓ Преземи PDF и испрати му го на купувачот.</li>
                <li>✓ Претвори ја во излезна фактура — сите податоци се пополнети, а можеш да ги смениш.</li>
            </ul>
        </x-card>
    @else
        <div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
            {{-- Лева страна: список --}}
            <x-card padding="p-0" class="overflow-hidden {{ $selected ? 'hidden lg:block' : '' }}">
                <div class="p-3 border-b border-sand flex items-center gap-2">
                    <select wire:model.live="filter" aria-label="Филтер" class="border-gray-300 rounded-md text-sm font-semibold flex-1">
                        <option value="all">Сите профактури</option>
                        <option value="draft">Нацрти</option>
                        <option value="confirmed">Потврдени</option>
                        <option value="converted">Претворени во фактура</option>
                        <option value="cancelled">Откажани</option>
                    </select>
                    @can('create', \App\Models\ProformaInvoice::class)
                        <a href="{{ route('proformas.create', $company) }}" wire:navigate
                           class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand text-white text-xl leading-none" title="Нова профактура" aria-label="Нова профактура">+</a>
                    @endcan
                </div>
                <div class="p-3 border-b border-sand">
                    <x-text-input wire:model.live="search" placeholder="Пребарувај по број, референца или купувач" class="w-full" />
                </div>

                <ul class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
                    @forelse ($proformas as $row)
                        <li wire:key="proforma-{{ $row->id }}">
                            <button type="button" wire:click="select({{ $row->id }})"
                                    class="w-full text-left px-4 py-2 hover:bg-orange-50 {{ $selected?->id === $row->id ? 'bg-orange-50' : '' }}">
                                <span class="flex items-baseline justify-between gap-3">
                                    <span class="text-sm truncate">{{ $row->partner->name }}</span>
                                    <span class="text-sm whitespace-nowrap">{{ \App\Support\Format::money($row->grandTotal(), \App\Support\Format::currencyLabel($row->currency)) }}</span>
                                </span>
                                <span class="flex items-center justify-between text-xs text-gray-500">
                                    <span>{{ $row->proforma_number_formatted }} · {{ \App\Support\Format::date($row->proforma_date) }}</span>
                                    <x-badge :status="$row->status === 'converted' ? 'paid' : ($row->status === 'confirmed' ? 'info' : $row->status)" class="!px-2 !py-0.5">{{ \App\Support\Format::proformaStatus($row->status) }}</x-badge>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-sm text-gray-500">Нема профактури.</li>
                    @endforelse
                </ul>
            </x-card>

            {{-- Десна страна: детали --}}
            <div class="{{ $selected ? '' : 'hidden lg:block' }}">
                @if ($selected)
                    @php
                        $label = \App\Support\Format::currencyLabel($selected->currency);
                    @endphp
                    <x-card>
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <h2 class="text-xl font-bold text-gray-800">{{ $selected->proforma_number_formatted }}</h2>
                            <button type="button" wire:click="closeDetail" class="text-gray-500 hover:text-gray-700 text-xl leading-none lg:hidden" aria-label="Назад">×</button>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-y border-sand py-2 mb-4 text-sm">
                            @can('update', $selected)
                                @if ($selected->isOpen())
                                    <a href="{{ route('proformas.edit', [$company, $selected]) }}" wire:navigate class="text-brand hover:underline">Уреди</a>
                                @endif
                            @endcan
                            <a href="{{ route('proformas.pdf', [$company, $selected]) }}" class="text-brand hover:underline">Преземи PDF</a>
                            @can('update', $selected)
                                @if ($selected->status === 'draft')
                                    <button type="button" wire:click="markConfirmed({{ $selected->id }})" class="text-brand hover:underline">Означи како потврдена</button>
                                @endif
                                @if ($selected->status === 'confirmed')
                                    <a href="{{ route('sales-invoices.create', [$company, 'proforma' => $selected->id]) }}" wire:navigate
                                       class="rounded-lg bg-brand px-3 py-1 text-white hover:opacity-90">Претвори во фактура</a>
                                @endif
                                @if ($selected->isOpen())
                                    <button type="button" wire:click="cancelProforma({{ $selected->id }})" wire:confirm="Да ја откажам профактурата?" class="ml-auto text-red-600 hover:underline">Откажи ја</button>
                                @endif
                            @endcan
                        </div>

                        @if ($error)
                            <p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $error }}</p>
                        @endif

                        @if ($selected->status === 'draft')
                            <p class="mb-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">Што следува? Означи ја профактурата како потврдена или преземи го PDF и испрати ја на купувачот.</p>
                        @elseif ($selected->status === 'confirmed')
                            <p class="mb-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">Што следува? Кога купувачот ќе ја прифати, претвори ја во фактура.</p>
                        @endif

                        <div class="grid gap-6 md:grid-cols-2 mb-6 text-sm">
                            <dl class="grid grid-cols-[11rem_1fr] gap-y-1 content-start">
                                <dt class="text-gray-500">Статус</dt>
                                <dd><x-badge :status="$selected->status === 'converted' ? 'paid' : ($selected->status === 'confirmed' ? 'info' : $selected->status)">{{ \App\Support\Format::proformaStatus($selected->status) }}</x-badge></dd>
                                <dt class="text-gray-500">Фактура</dt>
                                <dd>
                                    @if ($selected->salesInvoice)
                                        <a href="{{ route('sales-invoices.show', [$company, $selected->salesInvoice]) }}" wire:navigate class="text-brand hover:underline">{{ $selected->salesInvoice->formattedNumber() ?? 'Нацрт' }}</a>
                                    @else
                                        Не е фактурирана
                                    @endif
                                </dd>
                                <dt class="text-gray-500">Датум</dt>
                                <dd>{{ \App\Support\Format::date($selected->proforma_date) }}</dd>
                                @if ($selected->reference)
                                    <dt class="text-gray-500">Референца</dt><dd>{{ $selected->reference }}</dd>
                                @endif
                                @if ($selected->expected_delivery_date)
                                    <dt class="text-gray-500">Очекувана испорака</dt><dd>{{ \App\Support\Format::date($selected->expected_delivery_date) }}</dd>
                                @endif
                                <dt class="text-gray-500">Рок на плаќање</dt>
                                <dd>{{ $selected->payment_terms_days === null ? '—' : ($selected->payment_terms_days === 0 ? 'По приемот' : $selected->payment_terms_days.' дена') }}</dd>
                            </dl>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Купувач</div>
                                <a href="{{ route('partners.index', [$company, 'partner' => $selected->partner_id]) }}" wire:navigate class="text-brand hover:underline font-medium">{{ $selected->partner->name }}</a>
                                @if ($selected->partner->tax_id) <div>ЕДБ: {{ $selected->partner->tax_id }}</div> @endif
                                @if ($selected->partner->printedAddress()) <div>{{ $selected->partner->printedAddress() }}</div> @endif
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase text-gray-500 bg-gray-50">
                                        <th class="py-1 px-3">Ставка</th>
                                        <th class="py-1 px-3 text-right">Кол.</th>
                                        <th class="py-1 px-3 text-right">Цена</th>
                                        <th class="py-1 px-3 text-right">ДДВ %</th>
                                        <th class="py-1 px-3 text-right">Износ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($selected->lines as $line)
                                        <tr>
                                            <td class="py-1 px-3">{{ $line->description }}@if ($line->item) <span class="text-gray-400">· {{ $line->item->unit_of_measure }}</span>@endif</td>
                                            <td class="py-1 px-3 text-right">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', '.'), '0'), ',') }}</td>
                                            <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($line->unit_price, $label) }}</td>
                                            <td class="py-1 px-3 text-right">{{ \App\Support\Format::rate($line->vat_rate) }}</td>
                                            <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($line->lineTotal(), $label) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <dl class="mt-4 ml-auto grid max-w-xs grid-cols-[1fr_auto] gap-y-1 text-sm">
                            <dt class="text-gray-500">Вкупно без ДДВ</dt><dd class="text-right">{{ \App\Support\Format::money($selected->subtotal(), $label) }}</dd>
                            <dt class="text-gray-500">ДДВ</dt><dd class="text-right">{{ \App\Support\Format::money($selected->vatTotal(), $label) }}</dd>
                            <dt class="font-semibold border-t border-sand pt-1">Вкупно</dt><dd class="text-right font-semibold border-t border-sand pt-1">{{ \App\Support\Format::money($selected->grandTotal(), $label) }}</dd>
                        </dl>

                        @if ($selected->notes || $selected->terms)
                            <div class="mt-6 space-y-3 text-sm">
                                @if ($selected->notes)
                                    <div><div class="text-xs uppercase tracking-wide text-gray-500">Белешка</div><p class="whitespace-pre-line">{{ $selected->notes }}</p></div>
                                @endif
                                @if ($selected->terms)
                                    <div><div class="text-xs uppercase tracking-wide text-gray-500">Услови</div><p class="whitespace-pre-line">{{ $selected->terms }}</p></div>
                                @endif
                            </div>
                        @endif
                    </x-card>
                @else
                    <x-card>
                        <p class="text-sm text-gray-500">Избери профактура од листата за да ги видиш деталите.</p>
                    </x-card>
                @endif
            </div>
        </div>
    @endif
</div>
