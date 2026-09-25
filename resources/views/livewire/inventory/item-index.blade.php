<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Артикли — {{ $company->name }}</h1>

    <div class="mb-4 flex flex-wrap items-center gap-4">
        @can('create', \App\Models\Item::class)
            <a href="{{ route('inventory.items.create', $company) }}" wire:navigate>
                <x-primary-button type="button">Нов артикл</x-primary-button>
            </a>
        @endcan
        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
            Масовен внес преку табела
        </a>
    </div>

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Пребарувај по назив или шифра" class="w-full max-w-sm" />
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Шифра</th>
                <th class="py-1 px-3">Назив</th>
                <th class="py-1 px-3">Мерна единица</th>
                <th class="py-1 px-3">Категорија</th>
                <th class="py-1 px-3">ДДВ %</th>
                <th class="py-1 px-3">Продажна цена</th>
                <th class="py-1 px-3">Набавна цена</th>
                <th class="py-1 px-3">Тип</th>
                <th class="py-1 px-3">МК-производство</th>
                <th class="py-1 px-3">Баркод</th>
                <th class="py-1 px-3">Активен</th>
                <th class="py-1 px-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm hover:bg-orange-50 {{ $item->is_active ? '' : 'text-gray-400' }}" wire:key="item-{{ $item->id }}">
                    <td class="py-1 px-3 font-mono">{{ $item->code }}</td>
                    <td class="py-1 px-3">{{ $item->name }}</td>
                    <td class="py-1 px-3">{{ $item->unit_of_measure }}</td>
                    <td class="py-1 px-3">{{ $item->category }}</td>
                    <td class="py-1 px-3">{{ $item->vat_rate }}</td>
                    <td class="py-1 px-3">{{ $item->selling_price !== null ? \App\Support\Format::money($item->selling_price) : '—' }}</td>
                    <td class="py-1 px-3">{{ $item->cost_price !== null ? \App\Support\Format::money($item->cost_price) : '—' }}</td>
                    <td class="py-1 px-3">{{ \App\Support\Format::itemType($item->type) }}</td>
                    <td class="py-1 px-3">{{ $item->is_made_in_mk ? 'Да' : 'Не' }}</td>
                    <td class="py-1 px-3 font-mono">{{ $item->barcode }}</td>
                    <td class="py-1 px-3">{{ $item->is_active ? 'Да' : 'Не' }}</td>
                    <td class="py-1 px-3 whitespace-nowrap">
                        @can('update', $item)
                            <a href="{{ route('inventory.items.edit', [$company, $item]) }}" wire:navigate class="text-brand hover:underline text-sm mr-3">Уреди</a>
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-brand hover:underline text-sm">
                                {{ $item->is_active ? 'Деактивирај' : 'Активирај' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" class="py-4 px-3 text-gray-500">Нема додадено артикли.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
