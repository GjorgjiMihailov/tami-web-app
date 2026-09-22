<div>
    <h1 class="text-lg font-bold text-ink">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-stone">Продажба · работна година {{ $workingYear }}</p>

    {{-- Долго име на променливата намерно: @foreach ја презапишува променливата
         и по јамката, Blade нема опсег на јамка. --}}
    <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ($this->links() as $boardLink)
            <a href="{{ $boardLink['url'] }}" wire:navigate
               class="board-link {{ $boardLink['tone'] }} press"
               style="--i: {{ $loop->index }}">
                <span class="board-link__icon" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $boardLink['icon'] }}" />
                    </svg>
                </span>
                <span class="board-link__label">{{ $boardLink['label'] }}</span>
                <svg class="board-link__arrow h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                </svg>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        @if ($recentSales !== null)
            <x-card padding="p-0" class="overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                    <span class="text-xs font-semibold tracking-wide text-stone">Последни излезни фактури</span>
                    <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentSales as $salesRow)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">
                                    <a href="{{ route('sales-invoices.show', [$company, $salesRow]) }}" wire:navigate class="text-brand hover:underline">
                                        {{ $salesRow->formattedNumber() ?? '—' }}
                                    </a>
                                </td>
                                <td class="py-1 px-3 truncate">{{ $salesRow->partner?->name ?? '—' }}</td>
                                <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($salesRow->grandTotal()) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени излезни фактури за {{ $workingYear }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif

        @if ($recentPurchases !== null)
            <x-card padding="p-0" class="overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                    <span class="text-xs font-semibold tracking-wide text-stone">Последни влезни фактури</span>
                    <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentPurchases as $purchaseRow)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">
                                    <a href="{{ route('purchase-invoices.show', [$company, $purchaseRow]) }}" wire:navigate class="text-brand hover:underline">
                                        {{ $purchaseRow->supplier_invoice_number ?: '—' }}
                                    </a>
                                </td>
                                <td class="py-1 px-3 truncate">{{ $purchaseRow->partner?->name ?? '—' }}</td>
                                <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($purchaseRow->grandTotal()) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени влезни фактури за {{ $workingYear }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif

        <x-card padding="p-0" class="overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                <span class="text-xs font-semibold tracking-wide text-stone">Кооперанти</span>
                <a href="{{ route('partners.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentPartners as $partnerRow)
                        <tr class="text-sm hover:bg-orange-50">
                            <td class="py-1 px-3">
                                <a href="{{ route('partners.show', [$company, $partnerRow]) }}" wire:navigate class="text-brand hover:underline">
                                    {{ $partnerRow->name }}
                                </a>
                            </td>
                            <td class="py-1 px-3 text-gray-500 whitespace-nowrap">{{ $partnerRow->tax_id ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени кооперанти</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
</div>
