<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Контен план — {{ $company->name }}</h1>

    @can('create', \App\Models\Account::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади аналитичка сметка</h2>
            <form wire:submit="addAnalyticalAccount" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newParentCode" value="Родител (шифра на постоечко конто)" />
                    <x-text-input id="newParentCode" wire:model="newParentCode" class="w-32" />
                    @error('newParentCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newCode" value="Нова шифра (4+ цифри)" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-32" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-text-input wire:model.live.debounce.300ms="search" class="w-72" placeholder="Барај по шифра или назив" />
        <span class="text-xs text-gray-500">
            <span class="font-semibold">з.д.</span> — износ само на ДОЛЖИ ·
            <span class="font-semibold">з.п.</span> — износ само на ПОБАРУВА
        </span>
    </div>

    @foreach ($accountsByClass as $class => $accounts)
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Класа {{ $class }}</h3>
            <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-1 px-3">Шифра</th>
                        <th class="py-1 px-3">Назив</th>
                        <th class="py-1 px-3">Правило</th>
                        <th class="py-1 px-3">Активна</th>
                        <th class="py-1 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $account)
                        @php
                            $heading = match ($account->level) {
                                'class' => 'font-bold bg-gray-100',
                                'group' => 'font-bold',
                                'subgroup' => 'font-semibold',
                                default => '',
                            };
                        @endphp
                        <tr class="text-sm hover:bg-orange-50 {{ $heading }} {{ $account->is_active ? '' : 'text-gray-400' }}">
                            <td class="py-1 px-3 font-mono">{{ $account->code }}</td>
                            <td class="py-1 px-3" style="padding-left: {{ 0.75 + max(strlen($account->code) - 1, 0) * 0.5 }}rem">{{ $account->name }}</td>
                            <td class="py-1 px-3 text-xs">{{ $account->must_debit ? 'з.д.' : ($account->must_credit ? 'з.п.' : '') }}</td>
                            <td class="py-1 px-3">{{ $account->is_active ? 'Да' : 'Не' }}</td>
                            <td class="py-1 px-3">
                                @if ($canUpdate)
                                    <button type="button" wire:click="toggleActive({{ $account->id }})" class="text-brand hover:underline text-sm">
                                        {{ $account->is_active ? 'Деактивирај' : 'Активирај' }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </x-card>
        </div>
    @endforeach
</div>
