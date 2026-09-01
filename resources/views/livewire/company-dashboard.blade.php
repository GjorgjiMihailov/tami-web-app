<div class="p-4">
    <h1 class="text-lg font-medium text-gray-900">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-gray-500">{{ $company->type->label() }}</p>

    @if ($company->type->isLegal())
        <p class="mt-1 text-xs text-gray-400">Работна година {{ $workingYear }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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

            @if ($canSeeVat)
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                    <span class="text-sm text-gray-500">ДДВ за тековниот период</span>
                    <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($vatDue) }}</p>
                </a>
            @endif

            <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">Вредност на залихата</span>
                <p class="mt-1 text-xl font-semibold text-gray-800">{{ \App\Support\Format::money($stockValue) }}</p>
            </a>

            <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="text-sm text-gray-500">е-Фактура: испратени и со грешка</span>
                <p class="mt-1 text-sm text-gray-800">{{ $efakturaSent }} испратени</p>
                <p class="mt-1 text-sm {{ $efakturaFailed > 0 ? 'text-red-600 font-semibold' : 'text-gray-800' }}">
                    {{ $efakturaFailed }} со грешка
                </p>
            </a>
        </div>
    @endif
</div>
