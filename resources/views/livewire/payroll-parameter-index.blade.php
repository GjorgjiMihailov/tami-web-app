<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Параметри за пресметка на плата</h1>
    <p class="text-sm text-gray-500 mb-4">
        Стапките и основиците важат за сите фирми. Секоја промена се внесува како нов период —
        постојните периоди остануваат, за да може стара пресметка да се повтори точно.
    </p>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-3">Нов период</h2>
        <form wire:submit="addPeriod" class="grid gap-3 md:grid-cols-4">
            <div>
                <x-input-label for="effectiveFrom" value="Важи од" />
                <x-text-input id="effectiveFrom" type="date" wire:model="effectiveFrom" class="w-full" />
                @error('effectiveFrom') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="ratePension" value="ПИО %" />
                <x-text-input id="ratePension" wire:model="ratePension" class="w-full" />
                @error('ratePension') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="rateHealth" value="Здравствено %" />
                <x-text-input id="rateHealth" wire:model="rateHealth" class="w-full" />
            </div>
            <div>
                <x-input-label for="rateInjury" value="Повреда %" />
                <x-text-input id="rateInjury" wire:model="rateInjury" class="w-full" />
            </div>
            <div>
                <x-input-label for="rateUnemployment" value="Невработеност %" />
                <x-text-input id="rateUnemployment" wire:model="rateUnemployment" class="w-full" />
                @error('rateUnemployment') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="rateTax" value="ПДД %" />
                <x-text-input id="rateTax" wire:model="rateTax" class="w-full" />
            </div>
            <div>
                <x-input-label for="personalAllowance" value="Лично ослободување" />
                <x-text-input id="personalAllowance" wire:model="personalAllowance" class="w-full" />
                @error('personalAllowance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="averageSalary" value="Просечна плата" />
                <x-text-input id="averageSalary" wire:model="averageSalary" class="w-full" />
            </div>
            <div>
                <x-input-label for="minBase" value="Најниска основица" />
                <x-text-input id="minBase" wire:model="minBase" class="w-full" />
            </div>
            <div>
                <x-input-label for="maxBase" value="Највисока основица" />
                <x-text-input id="maxBase" wire:model="maxBase" class="w-full" />
            </div>
            <div>
                <x-input-label for="minimumWage" value="Минимална плата" />
                <x-text-input id="minimumWage" wire:model="minimumWage" class="w-full" />
            </div>
            <div class="self-end">
                <x-primary-button type="submit">Додади</x-primary-button>
            </div>
        </form>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Важи од</th>
                <th class="py-1 px-3">ПИО</th>
                <th class="py-1 px-3">Здравствено</th>
                <th class="py-1 px-3">Повреда</th>
                <th class="py-1 px-3">Невработеност</th>
                <th class="py-1 px-3">ПДД</th>
                <th class="py-1 px-3">Лично ослоб.</th>
                <th class="py-1 px-3">Најниска</th>
                <th class="py-1 px-3">Највисока</th>
                <th class="py-1 px-3">Мин. плата</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($parameters as $p)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">{{ $p->effective_from->format('d.m.Y') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_pension, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_health, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_injury, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_unemployment, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_tax, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->personal_allowance, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->min_base, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->max_base, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->minimum_wage, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </x-card>
</div>
