<div class="p-4">
    @if ($apps !== [])
        {{-- Изгледот на секоја плочка (боја, икона, опис) е само украс, па
             живее тука, а не во AppSwitcher — таму е правилото КОЈ што гледа. --}}
        {{-- Описот зависи од видот на фирмата: физичко лице нема ни ДДВ ни
             книжење, па плочката не смее да ги ветува. --}}
        @php
            $individual = $company->type->isIndividual();
            $tileLook = [
                'prodazba' => [
                    'tone' => 'app-tile--orange',
                    'text' => $individual ? 'Фактури и наплата' : 'Фактури, кооперанти, магацин и залиха',
                    'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
                ],
                'finansii' => [
                    'tone' => 'app-tile--green',
                    'text' => $individual ? '743 обрасци и пријави' : 'Книжење, извештаи, ДДВ и изводи',
                    'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                ],
                'plata' => [
                    'tone' => 'app-tile--indigo',
                    'text' => $individual ? 'Пријави за е-ПДД' : 'Вработени, пресметка на плата и МПИН',
                    'icon' => 'M17 20h5v-2a3 3 0 00-5.4-1.9M17 20H7m10 0v-2c0-.7-.1-1.3-.4-1.9M7 20H2v-2a3 3 0 015.4-1.9M7 20v-2c0-.7.1-1.3.4-1.9m0 0a5 5 0 019.2 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                ],
            ];
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            @foreach ($apps as $app)
                @php $look = $tileLook[$app['key']] ?? $tileLook['prodazba']; @endphp
                <a href="{{ $app['url'] }}"
                   class="app-tile {{ $look['tone'] }} press"
                   style="--i: {{ $loop->index }}">
                    <span class="app-tile__glow" aria-hidden="true"></span>
                    <span class="app-tile__icon" aria-hidden="true">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $look['icon'] }}" />
                        </svg>
                    </span>
                    <span class="relative block mt-4 text-base font-bold text-ink">{{ $app['label'] }}</span>
                    <span class="relative block mt-1 text-xs text-stone">{{ $look['text'] }}</span>
                    <span class="app-tile__cta">
                        Отвори ја апликацијата
                        <svg class="app-tile__arrow h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                        </svg>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <h1 class="text-lg font-medium text-gray-900">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-gray-500">{{ $company->type->label() }}</p>

    @if ($company->type->isLegal())
        <p class="mt-1 text-xs text-gray-400">Работна година {{ $workingYear }}</p>

        {{-- Плочките ги следат модулите на фирмата, исто како менито. Плочка
             што води кон екран затворен со `EnsureCompanyModule` не смее да
             стои тука — врската би завршила со „Забранет пристап". --}}
        @php
            $usesMaterial = $company->usesModule(\App\Support\CompanyModule::MATERIAL);
            $usesStock = $company->usesModule(\App\Support\CompanyModule::STOCK);
            $usesFinance = $company->usesModule(\App\Support\CompanyModule::FINANCE);
        @endphp

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @if ($usesMaterial)
            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Приход за работната година</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($revenue) }}</p>
            </a>

            <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Трошоци за работната година</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($costs) }}</p>
            </a>

            <div class="bg-white rounded-2xl shadow-card p-4">
                <span class="text-sm text-gray-500">Разлика</span>
                <p class="mt-1 text-xl font-semibold {{ bccomp($difference, '0', 2) < 0 ? 'text-red-600' : 'text-gray-800' }}">
                    {{ \App\Support\Format::money($difference) }}
                </p>
            </div>

            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Ненаплатено од купувачи</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($receivable) }}</p>
                <p class="mt-1 text-xs text-gray-500">од тоа доспеано: {{ \App\Support\Format::money($receivableOverdue) }}</p>
            </a>

            <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Обврски кон добавувачи</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($payable) }}</p>
                <p class="mt-1 text-xs text-gray-500">од тоа доспеано: {{ \App\Support\Format::money($payableOverdue) }}</p>
            </a>
            @endif

            @if ($canSeeVat && $usesFinance)
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                    <span class="text-sm text-gray-500">ДДВ за тековниот период</span>
                    <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($vatDue) }}</p>
                </a>
            @endif

            @if ($usesStock)
            <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Вредност на залихата</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($stockValue) }}</p>
            </a>
            @endif

            @if ($usesMaterial)
            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">е-Фактура: испратени и со грешка</span>
                <p class="mt-1 text-sm text-gray-800">{{ $efakturaSent }} испратени</p>
                <p class="mt-1 text-sm {{ $efakturaFailed > 0 ? 'text-red-600 font-semibold' : 'text-gray-800' }}">
                    {{ $efakturaFailed }} со грешка
                </p>
            </a>
            @endif
        </div>
    @endif

    @if ($company->type->isIndividual())
        <p class="mt-1 text-xs text-gray-400">Работна година {{ $workingYear }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Приход</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($revenue) }}</p>
            </a>

            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Ненаплатено</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($receivable) }}</p>
            </a>
        </div>

        {{-- Износот на ДЛД останува сив и без бројка: тој се знае дури
             откако пријавата е внесена во е-ПДД, а таму нема поврзување.
             Измислена бројка на почетен екран е полоша од именувана празнина. --}}
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('form743.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Примени 743 обрасци</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ $form743Counts['received'] }}</p>
            </a>

            <a href="{{ route('form743.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Необработени</span>
                <p class="mt-1 text-xl font-semibold {{ $form743Counts['pending'] > 0 ? 'text-amber-700' : 'text-gray-800' }}">
                    {{ $form743Counts['pending'] }}
                </p>
            </a>

            <a href="{{ route('form743.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Внесени пријави</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ $form743Counts['filed'] }}</p>
            </a>

            <div class="block bg-white rounded-2xl shadow-card p-4 opacity-60">
                <span class="font-semibold text-gray-500 flex items-center gap-2">
                    Износ на ДЛД
                    <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">наскоро</span>
                </span>
            </div>
        </div>
    @endif
</div>
