<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Кооперанти — {{ $company->name }}</h1>
        @if ($partners->isNotEmpty())
            <div class="flex items-center gap-4">
                <a href="{{ route('partners.pdf', $company) }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
                @can('create', \App\Models\Partner::class)
                    <a href="{{ route('partners.create', $company) }}" wire:navigate>
                        <x-primary-button type="button">+ Нов кооперант</x-primary-button>
                    </a>
                @endcan
            </div>
        @endif
    </div>

    @if ($partners->isEmpty())
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
        <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-1 px-3">Назив</th>
                        <th class="py-1 px-3">ЕДБ</th>
                        <th class="py-1 px-3">Е-пошта</th>
                        <th class="py-1 px-3">Телефон</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($partners as $partner)
                        <tr class="text-sm hover:bg-orange-50">
                            <td class="py-1 px-3"><a href="{{ route('partners.show', [$company, $partner]) }}" class="text-brand hover:underline font-medium">{{ $partner->name }}</a></td>
                            <td class="py-1 px-3">{{ $partner->tax_id }}</td>
                            <td class="py-1 px-3">{{ $partner->email }}</td>
                            <td class="py-1 px-3">{{ $partner->phone }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif
</div>
