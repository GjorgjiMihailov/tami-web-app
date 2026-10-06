<div>
    <h1 class="text-lg font-bold text-ink">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-stone">Финансии · работна година {{ $workingYear }}</p>

    <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Долги имиња на променливите: @foreach ја презапишува променливата и по јамката. --}}
        @foreach ([
            ['key' => 'receivables', 'title' => 'Побарувања', 'caption' => 'Вкупно неплатени излезни фактури', 'summary' => $receivables, 'shares' => $receivableShares, 'actions' => $this->receivableActions()],
            ['key' => 'payables', 'title' => 'Обврски', 'caption' => 'Вкупно неплатени влезни фактури', 'summary' => $payables, 'shares' => $payableShares, 'actions' => $this->payableActions()],
        ] as $block)
            <x-card data-block="{{ $block['key'] }}">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink">{{ $block['title'] }}</h2>

                    @if ($block['actions'] !== [])
                        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                            <button type="button" @click="open = !open" class="inline-flex items-center gap-1 text-sm text-brand hover:underline">
                                <span aria-hidden="true">＋</span> Нова
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false" x-transition.opacity
                                 class="absolute right-0 z-10 mt-2 w-60 rounded-xl border border-sand bg-white py-1 shadow-card">
                                @foreach ($block['actions'] as $action)
                                    <a href="{{ $action['url'] }}" class="block px-4 py-2 text-sm text-ink hover:bg-orange-50">{{ $action['label'] }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <p class="mt-4 text-sm text-stone">{{ $block['caption'] }}</p>
                <p class="mt-1 text-2xl font-bold text-ink">{{ \App\Support\Format::money($block['summary']['total']) }}</p>

                <div class="mt-4 flex h-2 overflow-hidden rounded-full bg-sand" role="img"
                     aria-label="Тековно {{ $block['shares']['current'] }}%, задоцнето {{ $block['shares']['overdue'] }}%">
                    <div class="bg-brand" style="width: {{ $block['shares']['current'] }}%"></div>
                    <div class="bg-orange-400" style="width: {{ $block['shares']['overdue'] }}%"></div>
                </div>

                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span><span class="inline-block h-2 w-2 rounded-sm bg-brand" aria-hidden="true"></span> Тековно:
                        <span class="font-medium">{{ \App\Support\Format::money($block['summary']['current']) }}</span></span>
                    <span><span class="inline-block h-2 w-2 rounded-sm bg-orange-400" aria-hidden="true"></span> Задоцнето:
                        <span class="font-medium">{{ \App\Support\Format::money($block['summary']['overdue']) }}</span></span>
                </div>
            </x-card>
        @endforeach
    </div>

    @if ($cashFlow !== null)
        @php($monthNames = ['Јан', 'Фев', 'Мар', 'Апр', 'Мај', 'Јун', 'Јул', 'Авг', 'Сеп', 'Окт', 'Ное', 'Дек'])
        @php($totalIn = array_reduce($cashFlow['months'], fn ($carry, $m) => bcadd($carry, $m['in'], 2), '0.00'))
        @php($totalOut = array_reduce($cashFlow['months'], fn ($carry, $m) => bcadd($carry, $m['out'], 2), '0.00'))

        <x-card class="mt-4" data-block="cash-flow">
            <h2 class="text-base font-semibold text-ink">Готовински тек <span class="font-normal text-stone">· {{ $workingYear }}</span></h2>

            <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <div class="flex h-44 items-end gap-2 border-b border-sand">
                        @foreach ($cashFlow['months'] as $number => $month)
                            @php($inHeight = bccomp($cashPeak, '0', 2) > 0 ? max(bccomp($month['in'], '0', 2) > 0 ? 2 : 0, (int) round(((float) $month['in'] / (float) $cashPeak) * 100)) : 0)
                            @php($outHeight = bccomp($cashPeak, '0', 2) > 0 ? max(bccomp($month['out'], '0', 2) > 0 ? 2 : 0, (int) round(((float) $month['out'] / (float) $cashPeak) * 100)) : 0)
                            <div class="flex h-full flex-1 items-end justify-center gap-0.5">
                                <div class="w-2.5 rounded-t bg-emerald-500" style="height: {{ $inHeight }}%"
                                     title="{{ $monthNames[$number - 1] }}: уплати {{ \App\Support\Format::money($month['in']) }}"></div>
                                <div class="w-2.5 rounded-t bg-rose-500" style="height: {{ $outHeight }}%"
                                     title="{{ $monthNames[$number - 1] }}: исплати {{ \App\Support\Format::money($month['out']) }}"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-2 text-xs text-stone">
                        @foreach ($monthNames as $monthName)
                            <span class="flex-1 text-center">{{ $monthName }}</span>
                        @endforeach
                    </div>
                </div>

                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-stone">Пари на 1 јануари {{ $workingYear }}</dt>
                        <dd class="text-lg font-bold text-ink">{{ \App\Support\Format::money($cashFlow['opening']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone"><span class="inline-block h-2 w-2 rounded-sm bg-emerald-500" aria-hidden="true"></span> Уплати</dt>
                        <dd class="font-medium text-ink">{{ \App\Support\Format::money($totalIn) }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone"><span class="inline-block h-2 w-2 rounded-sm bg-rose-500" aria-hidden="true"></span> Исплати</dt>
                        <dd class="font-medium text-ink">{{ \App\Support\Format::money($totalOut) }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone">Пари на 31 декември {{ $workingYear }}</dt>
                        <dd class="text-lg font-bold text-ink">{{ \App\Support\Format::money($cashFlow['closing']) }}</dd>
                    </div>
                </dl>
            </div>
        </x-card>
    @endif
</div>
