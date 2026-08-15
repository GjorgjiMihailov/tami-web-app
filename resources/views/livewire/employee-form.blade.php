<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $employee ? 'Картон на вработен' : 'Нов вработен' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save">
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Лични податоци</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="embg" value="ЕМБГ" />
                    <x-text-input id="embg" wire:model="embg" class="w-full" />
                    @error('embg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="firstName" value="Име" />
                    <x-text-input id="firstName" wire:model="firstName" class="w-full" />
                    @error('firstName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="lastName" value="Презиме" />
                    <x-text-input id="lastName" wire:model="lastName" class="w-full" />
                    @error('lastName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="municipalityCode" value="Општина" />
                    <select id="municipalityCode" wire:model="municipalityCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($municipalities as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                    @error('municipalityCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="bankAccount" value="Трансакциска сметка" />
                    <x-text-input id="bankAccount" wire:model="bankAccount" class="w-full" />
                    @error('bankAccount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="address" value="Адреса" />
                    <x-text-input id="address" wire:model="address" class="w-full" />
                </div>
                <div>
                    <x-input-label for="phone" value="Телефон" />
                    <x-text-input id="phone" wire:model="phone" class="w-full" />
                </div>
                <div>
                    <x-input-label for="email" value="Е-пошта" />
                    <x-text-input id="email" wire:model="email" class="w-full" />
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Работен однос</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="insuranceTypeCode" value="Вид на стаж" />
                    <select id="insuranceTypeCode" wire:model="insuranceTypeCode" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach ($insuranceTypes as $code)
                            <option value="{{ $code->code }}">{{ $code->code }} — {{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="registrationNumber" value="Број на пријава (М1/М2)" />
                    <x-text-input id="registrationNumber" wire:model="registrationNumber" class="w-full" />
                </div>
                <div>
                    <x-input-label for="weeklyHours" value="Часови неделно" />
                    <x-text-input id="weeklyHours" type="number" wire:model="weeklyHours" class="w-full" />
                    @error('weeklyHours') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="priorServiceMonths" value="Претходен стаж (месеци)" />
                    <x-text-input id="priorServiceMonths" type="number" min="0" wire:model="priorServiceMonths" class="w-full" />
                    <p class="text-xs text-gray-400 mt-1">Стаж кај претходни работодавачи, за пресметка на минат труд.</p>
                    @error('priorServiceMonths') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="employedOn" value="Вработен од" />
                    <x-text-input id="employedOn" type="date" wire:model="employedOn" class="w-full" />
                    @error('employedOn') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="terminatedOn" value="Престанок" />
                    <x-text-input id="terminatedOn" type="date" wire:model="terminatedOn" class="w-full" />
                    @error('terminatedOn') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="movementCode" value="Шифра на движење" />
                    <select id="movementCode" wire:model="movementCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($movements as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="exemptionCode" value="Даночно намалување" />
                    <select id="exemptionCode" wire:model="exemptionCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($exemptions as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-1">Договорена плата</h2>

            <p class="text-sm mb-1">
                @if ($currentSalary)
                    <span class="text-gray-500">Важечка плата на {{ $asOf->format('d.m.Y') }}:</span>
                    <span class="font-medium text-gray-800">{{ number_format($currentSalary->amount, 0, ',', '.') }}</span>
                    <span class="text-gray-500">{{ $currentSalary->basis === 'gross' ? 'бруто' : 'нето' }} — важи од {{ $currentSalary->effective_from->format('d.m.Y') }}</span>
                    @if ($currentSalary->effective_from->year < $workingYear)
                        <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">Запис од {{ $currentSalary->effective_from->year }}</span>
                    @endif
                @else
                    <span class="text-gray-500">Сè уште нема договорена плата.</span>
                @endif
            </p>

            <p class="text-sm text-gray-500 mb-3">Внесете во едното поле за да договорите нова плата — другото се пресметува автоматски.</p>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="salaryEffectiveFrom" value="Важи од" />
                    <x-text-input id="salaryEffectiveFrom" type="date" wire:model.live="salaryEffectiveFrom" class="w-full" />
                    @error('salaryEffectiveFrom') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="gross" value="Бруто" />
                    <x-text-input id="gross" wire:model.live.debounce.500ms="gross" class="w-full" />
                </div>
                <div>
                    <x-input-label for="net" value="Нето" />
                    <x-text-input id="net" wire:model.live.debounce.500ms="net" class="w-full" />
                </div>
            </div>

            @if ($history->isNotEmpty())
                <h3 class="font-semibold text-gray-700 mt-5 mb-2 text-sm">Историја на платата</h3>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">Важи од</th>
                            <th class="py-1 px-3">Износ</th>
                            <th class="py-1 px-3">Договорено како</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($history as $record)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">{{ $record->effective_from->format('d.m.Y') }}</td>
                                <td class="py-1 px-3">{{ number_format($record->amount, 0, ',', '.') }}</td>
                                <td class="py-1 px-3">{{ $record->basis === 'gross' ? 'бруто' : 'нето' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        <div class="flex gap-3">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ route('employees.index', $company) }}" class="text-gray-600 hover:underline text-sm self-center">Откажи</a>
        </div>
    </form>
</div>
