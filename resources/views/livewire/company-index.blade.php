<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Фирми</h1>

    @can('create', \App\Models\Company::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади фирма</h2>
            <form wire:submit="addCompany" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newType" value="Вид на клиент" />
                    {{-- .live, зашто полето под изборот (ЕДБ или ЕМБГ) зависи
                         од оваа вредност. Без .live Livewire 3 ја испраќа
                         промената дури при поднесување, па избраниот тип не би
                         го сменил полето пред очите на корисникот. --}}
                    <select id="newType" wire:model.live="newType" class="border-gray-300 rounded-md text-sm">
                        <option value="">— изберете —</option>
                        @foreach (\App\Support\CompanyType::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Не може да се смени подоцна.</p>
                    @error('newType') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                {{-- Правно лице има ЕДБ, физичко лице има ЕМБГ и нема ЕДБ —
                     табелата „Полиња по тип" во дизајнот. Додека тип не е
                     избран не се прикажува ниту едно од двете, зашто прво се
                     избира типот. Изборот погоре е wire:model.live, па
                     Livewire ја исцртува формата повторно при секоја промена. --}}
                @if ($newType === \App\Support\CompanyType::LEGAL->value)
                    <div>
                        <x-input-label for="newTaxId" value="ЕДБ" />
                        <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                        @error('newTaxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                @elseif ($newType === \App\Support\CompanyType::INDIVIDUAL->value)
                    <div>
                        <x-input-label for="newEmbg" value="ЕМБГ" />
                        <x-text-input id="newEmbg" wire:model="newEmbg" class="w-40" />
                        @error('newEmbg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                @endif
                <div>
                    <x-input-label for="newEmail" value="Е-пошта" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Телефон" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                    @error('newPhone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Адреса" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                    @error('newAddress') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                {{-- Модулите важат само за правно лице — кај физичко лице типот
                     веќе одлучува што се гледа. Изборот погоре е
                     wire:model.live, па блокот се појавува штом типот ќе се
                     избере. --}}
                @if ($newType === \App\Support\CompanyType::LEGAL->value)
                    <div class="w-full">
                        <span class="block text-sm font-medium text-gray-700 mb-1">Што користи клиентот</span>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <div>
                                <label class="flex items-center gap-2 text-sm">
                                    {{-- .live, зашто подкутијата Залиха сивее
                                         штом Материјално се отштиклира. --}}
                                    <input type="checkbox" wire:model.live="newUsesMaterial">
                                    Материјално работење
                                </label>
                                <label class="flex items-center gap-2 text-sm ms-6 mt-1 {{ $newUsesMaterial ? '' : 'text-gray-400' }}">
                                    <input type="checkbox" wire:model="newUsesStock" @disabled(! $newUsesMaterial)>
                                    Залиха
                                </label>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="newUsesPayroll">
                                Плата
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="newUsesFinance">
                                Финансии
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Може да се смени подоцна во профилот на фирмата.</p>
                    </div>
                @endif
                <x-primary-button type="submit">Додади фирма</x-primary-button>
            </form>
        </x-card>
    @endcan

    @if ($companies->isEmpty())
        <p class="text-gray-500">Нема додадено фирми.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <span class="font-medium">{{ $company->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
