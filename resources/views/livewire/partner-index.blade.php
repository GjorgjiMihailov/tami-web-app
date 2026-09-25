<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Кооперанти — {{ $company->name }}</h1>
        @if ($hasPartners)
            <a href="{{ route('partners.pdf', $company) }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
        @endif
    </div>

    @if (! $hasPartners)
        <x-card class="py-16 text-center">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-orange-50 text-brand" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.118a7.5 7.5 0 0115 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.5-1.632z" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">Секоја продажба почнува со кооперант</h2>
            <p class="mt-1 text-sm text-gray-500">Додади ги купувачите и добавувачите на едно место — со ЕДБ, е-пошта, телефон и адреса.</p>
            @can('create', \App\Models\Partner::class)
                <a href="{{ route('partners.create', $company) }}" wire:navigate class="mt-6 inline-block">
                    <x-primary-button type="button">+ Додади нов кооперант</x-primary-button>
                </a>
            @endcan
        </x-card>
    @else
        <div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
            {{-- Лева страна: список --}}
            <x-card padding="p-0" class="overflow-hidden {{ $selected ? 'hidden lg:block' : '' }}">
                <div class="p-3 border-b border-sand flex items-center gap-2">
                    <select wire:model.live="filter" aria-label="Филтер" class="border-gray-300 rounded-md text-sm font-semibold flex-1">
                        <option value="all">Сите кооперанти</option>
                        <option value="owed">Со ненаплатено побарување</option>
                        <option value="legal_entity">Правни лица</option>
                        <option value="individual">Физички лица</option>
                    </select>
                    @can('create', \App\Models\Partner::class)
                        <a href="{{ route('partners.create', $company) }}" wire:navigate
                           class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand text-white text-xl leading-none" title="Нов кооперант" aria-label="Нов кооперант">+</a>
                    @endcan
                </div>
                <div class="p-3 border-b border-sand">
                    <x-text-input wire:model.live="search" placeholder="Пребарувај по назив или ЕДБ" class="w-full" />
                </div>

                <ul class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
                    @forelse ($partners as $partner)
                        @php
                            $partnerOwed = collect($owed[$partner->id] ?? [])->filter(fn ($amount, $currency) => $currency === 'MKD' || bccomp($amount, '0', 2) !== 0);
                            $mkd = $partnerOwed['MKD'] ?? '0.00';
                        @endphp
                        <li wire:key="partner-{{ $partner->id }}">
                            <button type="button" wire:click="select({{ $partner->id }})"
                                    class="w-full text-left px-4 py-2 hover:bg-orange-50 {{ $selected?->id === $partner->id ? 'bg-orange-50' : '' }}">
                                <span class="block text-sm truncate">{{ $partner->name }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ \App\Support\Format::money($mkd) }}@foreach ($partnerOwed->except('MKD') as $currency => $amount) · {{ \App\Support\Format::money($amount, $currency) }}@endforeach
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-sm text-gray-500">Нема кооперанти.</li>
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
                                <p class="text-sm text-gray-500">{{ \App\Support\Format::partnerType($selected->type) }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                @can('update', $selected)
                                    <a href="{{ route('partners.edit', [$company, $selected]) }}" wire:navigate class="text-brand hover:underline text-sm">Уреди</a>
                                @endcan
                                <button type="button" wire:click="closeDetail" class="text-gray-500 hover:text-gray-700 text-xl leading-none lg:hidden" aria-label="Назад">×</button>
                            </div>
                        </div>

                        <nav class="flex gap-1 border-b border-sand mb-4">
                            @foreach (['overview' => 'Преглед', 'transactions' => 'Трансакции', 'statement' => 'Извод'] as $key => $label)
                                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                                        class="px-4 py-2 text-sm font-medium rounded-t-lg {{ $tab === $key ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </nav>

                        {{-- ПРЕГЛЕД --}}
                        @if ($tab === 'overview')
                            @php
                                $dash = fn ($value) => filled($value) ? $value : '—';
                                $shipping = trim(implode(', ', array_filter([
                                    trim($selected->shipping_street_address.' '.$selected->shipping_street_number),
                                    trim($selected->shipping_postal_code.' '.$selected->shipping_city),
                                    $selected->shipping_country,
                                ])));
                            @endphp

                            <div class="rounded-2xl bg-gray-50 p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
                                <div class="text-sm space-y-0.5">
                                    @if ($selected->contact_first_name || $selected->contact_last_name)
                                        <div class="font-medium text-gray-800">{{ trim(implode(' ', array_filter([$selected->contact_salutation, $selected->contact_first_name, $selected->contact_last_name]))) }}</div>
                                    @endif
                                    @if ($selected->email) <div>{{ $selected->email }}</div> @endif
                                    @if ($selected->phone) <div>{{ $selected->phone }}</div> @endif
                                    @if ($selected->mobile) <div>{{ $selected->mobile }}</div> @endif
                                    @unless ($selected->email || $selected->phone || $selected->mobile) <div class="text-gray-500">Нема податоци за контакт.</div> @endunless
                                </div>
                                @if ($company->usesModule(\App\Support\CompanyModule::MATERIAL))
                                    <a href="{{ route('sales-invoices.create', [$company, 'partner' => $selected->id]) }}" wire:navigate>
                                        <x-primary-button type="button">Нова фактура</x-primary-button>
                                    </a>
                                @endif
                            </div>

                            <h3 class="font-semibold text-gray-700 mb-2">Адреса</h3>
                            <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-sm mb-6">
                                <dt class="text-gray-500">За фактурирање</dt>
                                <dd>{{ $dash($selected->printedAddress()) }}</dd>
                                <dt class="text-gray-500">За испорака</dt>
                                <dd>{{ $dash($shipping) }}</dd>
                            </dl>

                            <h3 class="font-semibold text-gray-700 mb-2">Други податоци</h3>
                            <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-sm mb-6">
                                <dt class="text-gray-500">Тип</dt>
                                <dd>{{ \App\Support\Format::partnerType($selected->type) }}</dd>
                                <dt class="text-gray-500">ЕДБ</dt>
                                <dd>{{ $dash($selected->tax_id) }}</dd>
                                @if ($selected->type === 'legal_entity')
                                    <dt class="text-gray-500">ЕМБС</dt>
                                    <dd>{{ $dash($selected->registration_number) }}</dd>
                                    <dt class="text-gray-500">Директор</dt>
                                    <dd>{{ $dash($selected->director_name) }}</dd>
                                    <dt class="text-gray-500">Обврзник на ДДВ</dt>
                                    <dd>{{ $selected->is_vat_registered ? 'Да' : 'Не' }}</dd>
                                    @if ($selected->is_vat_registered)
                                        <dt class="text-gray-500">ДДВ-број</dt>
                                        <dd>{{ $dash($selected->vat_number) }}</dd>
                                    @endif
                                @endif
                                <dt class="text-gray-500">Рок на плаќање</dt>
                                <dd>{{ $selected->payment_terms_days === null ? '—' : ($selected->payment_terms_days === 0 ? 'По приемот' : $selected->payment_terms_days.' дена') }}</dd>
                                @if ($company->type->isIndividual())
                                    <dt class="text-gray-500">Јазик на фактура</dt>
                                    <dd>{{ $selected->invoice_language->label() }}</dd>
                                @endif
                                <dt class="text-gray-500">Трансакциски сметки</dt>
                                <dd>
                                    @forelse ($selected->bankAccounts as $bankAccount)
                                        <div>{{ $bankAccount->bank_name ? $bankAccount->bank_name.': ' : '' }}{{ $bankAccount->account_number }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </dd>
                            </dl>

                            <h3 class="font-semibold text-gray-700 mb-2">Контакт лица</h3>
                            @forelse ($selected->contacts as $contact)
                                <div class="text-sm mb-1">{{ $contact->fullName() }}@if ($contact->email) · {{ $contact->email }}@endif @if ($contact->phone) · {{ $contact->phone }}@endif @if ($contact->mobile) · {{ $contact->mobile }}@endif</div>
                            @empty
                                <p class="text-sm text-gray-500 mb-1">Нема контакт лица.</p>
                            @endforelse

                            <h3 class="font-semibold text-gray-700 mt-6 mb-2">Побарувања</h3>
                            <table class="min-w-full text-sm mb-2">
                                <thead>
                                    <tr class="text-left text-xs uppercase text-gray-500 bg-gray-50">
                                        <th class="py-1 px-3">Валута</th>
                                        <th class="py-1 px-3 text-right">Ненаплатено</th>
                                        <th class="py-1 px-3 text-right">Од тоа доспеано</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($receivables as $row)
                                        <tr>
                                            <td class="py-1 px-3">{{ $row['currency'] }}</td>
                                            <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($row['outstanding'], \App\Support\Format::currencyLabel($row['currency'])) }}</td>
                                            <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($row['overdue'], \App\Support\Format::currencyLabel($row['currency'])) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="py-1 px-3">MKD</td>
                                            <td class="py-1 px-3 text-right">{{ \App\Support\Format::money('0.00') }}</td>
                                            <td class="py-1 px-3 text-right">{{ \App\Support\Format::money('0.00') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            @if (bccomp($payables, '0', 2) > 0)
                                <p class="text-sm mb-2"><span class="text-gray-500">Неплатено кон кооперантот (влезни фактури):</span> <strong>{{ \App\Support\Format::money($payables) }}</strong></p>
                            @endif

                            <div class="mt-6">
                                <livewire:document-manager :documentable="$selected" :key="'partner-docs-'.$selected->id" />
                            </div>

                        {{-- ТРАНСАКЦИИ --}}
                        @elseif ($tab === 'transactions')
                            <div x-data="{ open: 'invoices' }" class="space-y-3">
                                @php
                                    $section = 'rounded-2xl border border-sand';
                                    $head = 'w-full flex items-center justify-between px-4 py-3 text-left font-semibold text-gray-700';
                                    $th = 'py-1 px-3 text-left text-xs uppercase text-gray-500 bg-gray-50';
                                @endphp

                                <div class="{{ $section }}">
                                    <button type="button" class="{{ $head }}" @click="open = open === 'invoices' ? '' : 'invoices'">
                                        <span>Излезни фактури ({{ $salesInvoices->count() }})</span>
                                        <span x-text="open === 'invoices' ? '−' : '+'"></span>
                                    </button>
                                    <div x-show="open === 'invoices'" class="px-4 pb-4">
                                        <div class="mb-2">
                                            <select wire:model.live="invoiceStatus" aria-label="Статус" class="border-gray-300 rounded-md text-sm">
                                                <option value="all">Статус: сите</option>
                                                <option value="draft">Нацрт</option>
                                                <option value="unpaid">Неплатена</option>
                                                <option value="partially_paid">Делумно платена</option>
                                                <option value="paid">Платена</option>
                                            </select>
                                        </div>
                                        <table class="min-w-full text-sm">
                                            <thead><tr><th class="{{ $th }}">Датум</th><th class="{{ $th }}">Број</th><th class="{{ $th }} text-right">Износ</th><th class="{{ $th }} text-right">За наплата</th><th class="{{ $th }}">Статус</th></tr></thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @forelse ($salesInvoices as $row)
                                                    @php $invoice = $row['invoice']; $label = \App\Support\Format::currencyLabel($invoice->currency); @endphp
                                                    <tr class="hover:bg-orange-50">
                                                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                                                        <td class="py-1 px-3"><a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" wire:navigate class="text-brand hover:underline">{{ $invoice->formattedNumber() ?? 'Нацрт' }}</a></td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['total'], $label) }}</td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ $row['balance'] === null ? '—' : \App\Support\Format::money($row['balance'], $label) }}</td>
                                                        <td class="py-1 px-3">{{ \App\Support\Format::documentStatus($row['status']) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="5" class="py-3 px-3 text-gray-500">Нема излезни фактури.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="{{ $section }}">
                                    <button type="button" class="{{ $head }}" @click="open = open === 'payments' ? '' : 'payments'">
                                        <span>Уплати од купувачот ({{ $salesPayments->count() }})</span>
                                        <span x-text="open === 'payments' ? '−' : '+'"></span>
                                    </button>
                                    <div x-show="open === 'payments'" x-cloak class="px-4 pb-4">
                                        <table class="min-w-full text-sm">
                                            <thead><tr><th class="{{ $th }}">Датум</th><th class="{{ $th }}">Фактура</th><th class="{{ $th }}">Начин</th><th class="{{ $th }} text-right">Износ</th></tr></thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @forelse ($salesPayments as $row)
                                                    <tr class="hover:bg-orange-50">
                                                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($row['payment']->payment_date) }}</td>
                                                        <td class="py-1 px-3"><a href="{{ route('sales-invoices.show', [$company, $row['invoice']]) }}" wire:navigate class="text-brand hover:underline">{{ $row['invoice']->formattedNumber() ?? 'Нацрт' }}</a></td>
                                                        <td class="py-1 px-3">{{ \App\Support\Format::paymentMethod((string) $row['payment']->payment_method) }}</td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['payment']->amount, \App\Support\Format::currencyLabel($row['invoice']->currency)) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="4" class="py-3 px-3 text-gray-500">Нема уплати.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="{{ $section }}">
                                    <button type="button" class="{{ $head }}" @click="open = open === 'bills' ? '' : 'bills'">
                                        <span>Влезни фактури ({{ $purchaseInvoices->count() }})</span>
                                        <span x-text="open === 'bills' ? '−' : '+'"></span>
                                    </button>
                                    <div x-show="open === 'bills'" x-cloak class="px-4 pb-4">
                                        <table class="min-w-full text-sm">
                                            <thead><tr><th class="{{ $th }}">Датум</th><th class="{{ $th }}">Број на добавувач</th><th class="{{ $th }} text-right">Износ</th><th class="{{ $th }} text-right">За плаќање</th><th class="{{ $th }}">Статус</th></tr></thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @forelse ($purchaseInvoices as $row)
                                                    <tr class="hover:bg-orange-50">
                                                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($row['invoice']->invoice_date) }}</td>
                                                        <td class="py-1 px-3"><a href="{{ route('purchase-invoices.show', [$company, $row['invoice']]) }}" wire:navigate class="text-brand hover:underline">{{ $row['invoice']->supplier_invoice_number }}</a></td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['total']) }}</td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ $row['balance'] === null ? '—' : \App\Support\Format::money($row['balance']) }}</td>
                                                        <td class="py-1 px-3">{{ \App\Support\Format::documentStatus($row['status']) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="5" class="py-3 px-3 text-gray-500">Нема влезни фактури.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="{{ $section }}">
                                    <button type="button" class="{{ $head }}" @click="open = open === 'billpayments' ? '' : 'billpayments'">
                                        <span>Плаќања кон добавувачот ({{ $purchasePayments->count() }})</span>
                                        <span x-text="open === 'billpayments' ? '−' : '+'"></span>
                                    </button>
                                    <div x-show="open === 'billpayments'" x-cloak class="px-4 pb-4">
                                        <table class="min-w-full text-sm">
                                            <thead><tr><th class="{{ $th }}">Датум</th><th class="{{ $th }}">Фактура</th><th class="{{ $th }}">Начин</th><th class="{{ $th }} text-right">Износ</th></tr></thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @forelse ($purchasePayments as $row)
                                                    <tr class="hover:bg-orange-50">
                                                        <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($row['payment']->payment_date) }}</td>
                                                        <td class="py-1 px-3"><a href="{{ route('purchase-invoices.show', [$company, $row['invoice']]) }}" wire:navigate class="text-brand hover:underline">{{ $row['invoice']->supplier_invoice_number }}</a></td>
                                                        <td class="py-1 px-3">{{ \App\Support\Format::paymentMethod((string) $row['payment']->payment_method) }}</td>
                                                        <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['payment']->amount) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="4" class="py-3 px-3 text-gray-500">Нема плаќања.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                        {{-- ИЗВОД --}}
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                <select wire:model.live="period" aria-label="Период" class="border-gray-300 rounded-md text-sm">
                                    <option value="this_month">Овој месец</option>
                                    <option value="last_month">Минатиот месец</option>
                                    <option value="this_year">Оваа година</option>
                                    <option value="last_year">Минатата година</option>
                                    <option value="all">Целиот период</option>
                                </select>
                                <a href="{{ route('partners.statement.pdf', [$company, $selected, 'period' => $period]) }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
                            </div>

                            <p class="text-sm text-gray-500 mb-4">
                                Извод на сметка за <strong>{{ $selected->name }}</strong>
                                — {{ $statement['from'] ? \App\Support\Format::date($statement['from']).' до ' : 'до ' }}{{ \App\Support\Format::date($statement['to']) }}
                            </p>

                            @foreach ($statement['currencies'] as $currency => $block)
                                @php $label = \App\Support\Format::currencyLabel($currency); @endphp
                                <div class="mb-6">
                                    @if (count($statement['currencies']) > 1 || $currency !== 'MKD')
                                        <h3 class="font-semibold text-gray-700 mb-2">Валута: {{ $currency }}</h3>
                                    @endif
                                    <dl class="grid grid-cols-[12rem_auto] gap-y-1 text-sm mb-3 max-w-sm">
                                        <dt class="text-gray-500">Почетно салдо</dt><dd class="text-right">{{ \App\Support\Format::money($block['opening'], $label) }}</dd>
                                        <dt class="text-gray-500">Фактурирано</dt><dd class="text-right">{{ \App\Support\Format::money($block['invoiced'], $label) }}</dd>
                                        <dt class="text-gray-500">Наплатено</dt><dd class="text-right">{{ \App\Support\Format::money($block['received'], $label) }}</dd>
                                        <dt class="font-semibold text-gray-700 border-t border-sand pt-1">Салдо за наплата</dt><dd class="text-right font-semibold border-t border-sand pt-1">{{ \App\Support\Format::money($block['closing'], $label) }}</dd>
                                    </dl>
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-xs uppercase text-gray-500 bg-gray-50">
                                                <th class="py-1 px-3">Датум</th>
                                                <th class="py-1 px-3">Документ</th>
                                                <th class="py-1 px-3">Детали</th>
                                                <th class="py-1 px-3 text-right">Износ</th>
                                                <th class="py-1 px-3 text-right">Уплата</th>
                                                <th class="py-1 px-3 text-right">Салдо</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr class="text-gray-500">
                                                <td class="py-1 px-3">{{ $statement['from'] ? \App\Support\Format::date($statement['from']) : '' }}</td>
                                                <td class="py-1 px-3" colspan="3">*** Почетно салдо ***</td>
                                                <td class="py-1 px-3"></td>
                                                <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($block['opening'], $label) }}</td>
                                            </tr>
                                            @foreach ($block['rows'] as $row)
                                                <tr class="hover:bg-orange-50">
                                                    <td class="py-1 px-3 whitespace-nowrap">{{ \App\Support\Format::date($row['date']) }}</td>
                                                    <td class="py-1 px-3">{{ $row['type'] === 'invoice' ? 'Фактура' : 'Уплата' }} {{ $row['document'] }}</td>
                                                    <td class="py-1 px-3">{{ $row['details'] }}</td>
                                                    <td class="py-1 px-3 text-right whitespace-nowrap">{{ $row['amount'] !== null ? \App\Support\Format::money($row['amount'], $label) : '' }}</td>
                                                    <td class="py-1 px-3 text-right whitespace-nowrap">{{ $row['payment'] !== null ? \App\Support\Format::money($row['payment'], $label) : '' }}</td>
                                                    <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['balance'], $label) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        @endif
                    </x-card>
                @else
                    <x-card>
                        <p class="text-sm text-gray-500">Избери кооперант од листата за да ги видиш деталите.</p>
                    </x-card>
                @endif
            </div>
        </div>
    @endif
</div>
