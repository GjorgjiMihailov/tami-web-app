<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $partner ? 'Уреди кооперант' : 'Нов кооперант' }} — {{ $company->name }}
    </h1>

    @php
        $errorKeys = collect($errors->keys());
        $tabHasErrors = fn (array $prefixes) => $errorKeys->contains(fn ($key) => collect($prefixes)->contains(fn ($p) => str_starts_with($key, $p)));
        $otherErrors = $tabHasErrors(['taxId', 'registrationNumber', 'directorName', 'vatNumber', 'paymentTermsDays', 'bankAccounts']);
        $addressErrors = $tabHasErrors(['country', 'streetAddress', 'streetNumber', 'postalCode', 'city', 'address', 'shipping']);
        $contactErrors = $tabHasErrors(['contacts']);
    @endphp

    <form wire:submit="save" x-data="{ tab: 'other' }">
        <x-card class="mb-4">
            <div class="grid gap-3 md:grid-cols-[12rem_1fr] items-center max-w-3xl">
                <span class="text-sm text-gray-700">Тип на кооперант</span>
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-1">
                        <input type="radio" wire:model.live="type" value="legal_entity"> {{ \App\Support\Format::partnerType('legal_entity') }}
                    </label>
                    <label class="flex items-center gap-1">
                        <input type="radio" wire:model.live="type" value="individual"> {{ \App\Support\Format::partnerType('individual') }}
                    </label>
                </div>

                <span class="text-sm text-gray-700">Примарен контакт</span>
                <div class="flex flex-wrap gap-2">
                    <select wire:model="contactSalutation" aria-label="Обраќање" class="border-gray-300 rounded-md text-sm">
                        <option value="">Обраќање</option>
                        @foreach (\App\Models\Partner::SALUTATIONS as $salutation)
                            <option value="{{ $salutation }}">{{ $salutation }}</option>
                        @endforeach
                    </select>
                    <x-text-input wire:model.live.debounce.300ms="contactFirstName" placeholder="Име" class="w-40" />
                    <x-text-input wire:model.live.debounce.300ms="contactLastName" placeholder="Презиме" class="w-40" />
                </div>

                <label for="name" class="text-sm text-red-600">Назив *</label>
                <div>
                    <x-text-input id="name" wire:model="name" list="name-suggestions" class="w-full" placeholder="Избери или впиши" />
                    <datalist id="name-suggestions">
                        @foreach ($nameSuggestions as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <label for="email" class="text-sm text-gray-700">Е-пошта</label>
                <div>
                    <x-text-input id="email" wire:model="email" class="w-full" />
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <span class="text-sm text-gray-700">Телефон</span>
                <div class="flex flex-wrap gap-2">
                    <x-text-input wire:model="phone" placeholder="Работен телефон (+389…)" aria-label="Работен телефон" class="w-56" />
                    <x-text-input wire:model="mobile" placeholder="Мобилен (+389…)" aria-label="Мобилен" class="w-56" />
                </div>

                @if ($company->type->isIndividual())
                    <label for="invoiceLanguage" class="text-sm text-gray-700">Јазик на фактура</label>
                    <select id="invoiceLanguage" wire:model="invoiceLanguage" class="border-gray-300 rounded-md text-sm w-56">
                        @foreach (\App\Support\InvoiceLanguage::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </x-card>

        <x-card class="mb-6">
            <nav class="flex gap-1 border-b border-sand mb-4" role="tablist">
                <button type="button" role="tab" @click="tab = 'other'"
                        :class="tab === 'other' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50'"
                        class="px-4 py-2 text-sm font-medium rounded-t-lg">
                    Други податоци @if ($otherErrors)<span class="text-red-500">●</span>@endif
                </button>
                <button type="button" role="tab" @click="tab = 'address'"
                        :class="tab === 'address' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50'"
                        class="px-4 py-2 text-sm font-medium rounded-t-lg">
                    Адреса @if ($addressErrors)<span class="text-red-500">●</span>@endif
                </button>
                <button type="button" role="tab" @click="tab = 'contacts'"
                        :class="tab === 'contacts' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50'"
                        class="px-4 py-2 text-sm font-medium rounded-t-lg">
                    Контакт лица @if ($contactErrors)<span class="text-red-500">●</span>@endif
                </button>
            </nav>

            {{-- Други податоци --}}
            <div x-show="tab === 'other'">
                <div class="grid gap-3 md:grid-cols-[12rem_1fr] items-center max-w-3xl">
                    <label for="taxId" class="text-sm text-gray-700">ЕДБ</label>
                    <div>
                        <x-text-input id="taxId" wire:model="taxId" class="w-full max-w-sm" />
                        @error('taxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>

                    @if ($type === 'legal_entity')
                        <label for="registrationNumber" class="text-sm text-gray-700">ЕМБС</label>
                        <x-text-input id="registrationNumber" wire:model="registrationNumber" class="w-full max-w-sm" />

                        <label for="directorName" class="text-sm text-gray-700">Име на директор</label>
                        <x-text-input id="directorName" wire:model="directorName" class="w-full max-w-sm" />

                        <span class="text-sm text-gray-700">Обврзник на ДДВ</span>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" id="isVatRegistered" wire:model.live="isVatRegistered"> Да
                        </label>

                        @if ($isVatRegistered)
                            <label for="vatNumber" class="text-sm text-gray-700">ДДВ-број</label>
                            <x-text-input id="vatNumber" wire:model="vatNumber" class="w-full max-w-sm" />
                        @endif
                    @endif

                    <label for="paymentTermsDays" class="text-sm text-gray-700">Рок на плаќање</label>
                    <div>
                        <select id="paymentTermsDays" wire:model="paymentTermsDays" class="border-gray-300 rounded-md text-sm w-56">
                            <option value="">Не е одредено</option>
                            @foreach (\App\Models\Partner::PAYMENT_TERMS as $days)
                                <option value="{{ $days }}">{{ $days === 0 ? 'По приемот' : "{$days} дена" }}</option>
                            @endforeach
                        </select>
                        <p class="text-gray-500 text-xs mt-1">Се користи за датумот на доспевање на нова излезна фактура.</p>
                        @error('paymentTermsDays') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                </div>

                <h3 class="text-sm font-semibold text-gray-700 mt-6 mb-2">Трансакциски сметки (до 5)</h3>
                <div class="space-y-2">
                    @foreach ($bankAccounts as $index => $row)
                        <div class="flex flex-wrap gap-3 items-end" wire:key="bank-{{ $index }}">
                            <div>
                                <x-input-label for="bank_name_{{ $index }}" value="Банка" />
                                <x-text-input id="bank_name_{{ $index }}" wire:model="bankAccounts.{{ $index }}.bank_name" class="w-48" />
                            </div>
                            <div>
                                <x-input-label for="account_number_{{ $index }}" value="Сметка (IBAN)" />
                                <x-text-input id="account_number_{{ $index }}" wire:model.live.blur="bankAccounts.{{ $index }}.account_number" class="w-64" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Адреса --}}
            <div x-show="tab === 'address'" x-cloak>
                <div class="grid gap-8 lg:grid-cols-2">
                    <div>
                        <h3 class="font-semibold text-gray-700 mb-3">Адреса за фактурирање</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="country" value="Држава" />
                                <x-text-input id="country" wire:model="country" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="streetAddress" value="Улица (за е-Фактура)" />
                                <x-text-input id="streetAddress" wire:model="streetAddress" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="streetNumber" value="Број" />
                                <x-text-input id="streetNumber" wire:model="streetNumber" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="postalCode" value="Поштенски број" />
                                <x-text-input id="postalCode" wire:model="postalCode" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="city" value="Град" />
                                <x-text-input id="city" wire:model="city" class="w-full" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="address" value="Адреса за печатење (по избор)" />
                                <x-text-input id="address" wire:model="address" class="w-full" />
                                <p class="text-gray-500 text-xs mt-1">Ако е празно, на фактурата се составува од полињата погоре.</p>
                                @error('address') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="font-semibold text-gray-700 mb-3">
                            Адреса за испорака
                            <button type="button" wire:click="copyBillingToShipping" class="ml-2 text-brand text-sm font-normal hover:underline">↓ Копирај ја адресата за фактурирање</button>
                        </h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="shippingCountry" value="Држава" />
                                <x-text-input id="shippingCountry" wire:model="shippingCountry" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="shippingStreetAddress" value="Улица" />
                                <x-text-input id="shippingStreetAddress" wire:model="shippingStreetAddress" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="shippingStreetNumber" value="Број" />
                                <x-text-input id="shippingStreetNumber" wire:model="shippingStreetNumber" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="shippingPostalCode" value="Поштенски број" />
                                <x-text-input id="shippingPostalCode" wire:model="shippingPostalCode" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="shippingCity" value="Град" />
                                <x-text-input id="shippingCity" wire:model="shippingCity" class="w-full" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Контакт лица --}}
            <div x-show="tab === 'contacts'" x-cloak>
                @if (count($contacts) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-gray-500 bg-gray-50">
                                    <th class="py-1 px-2">Обраќање</th>
                                    <th class="py-1 px-2">Име</th>
                                    <th class="py-1 px-2">Презиме</th>
                                    <th class="py-1 px-2">Е-пошта</th>
                                    <th class="py-1 px-2">Работен телефон</th>
                                    <th class="py-1 px-2">Мобилен</th>
                                    <th class="py-1 px-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($contacts as $index => $row)
                                    <tr wire:key="contact-{{ $index }}">
                                        <td class="py-1 px-2">
                                            <select wire:model="contacts.{{ $index }}.salutation" aria-label="Обраќање" class="border-gray-300 rounded-md text-sm">
                                                <option value=""></option>
                                                @foreach (\App\Models\Partner::SALUTATIONS as $salutation)
                                                    <option value="{{ $salutation }}">{{ $salutation }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-1 px-2"><x-text-input wire:model="contacts.{{ $index }}.first_name" class="w-32" aria-label="Име" /></td>
                                        <td class="py-1 px-2"><x-text-input wire:model="contacts.{{ $index }}.last_name" class="w-32" aria-label="Презиме" /></td>
                                        <td class="py-1 px-2">
                                            <x-text-input wire:model="contacts.{{ $index }}.email" class="w-48" aria-label="Е-пошта" />
                                            @error("contacts.{$index}.email") <span class="block text-red-600 text-xs">{{ $message }}</span> @enderror
                                        </td>
                                        <td class="py-1 px-2"><x-text-input wire:model="contacts.{{ $index }}.phone" class="w-36" aria-label="Работен телефон" /></td>
                                        <td class="py-1 px-2"><x-text-input wire:model="contacts.{{ $index }}.mobile" class="w-36" aria-label="Мобилен" /></td>
                                        <td class="py-1 px-2">
                                            <button type="button" wire:click="removeContact({{ $index }})" class="text-gray-400 hover:text-red-600" aria-label="Избриши контакт">×</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500 mb-3">Нема додадено контакт лица.</p>
                @endif

                <button type="button" wire:click="addContact" class="mt-3 text-brand text-sm hover:underline">+ Додади контакт лице</button>
            </div>
        </x-card>

        <div class="flex items-center gap-4">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ $partner ? route('partners.index', [$company, 'partner' => $partner->id]) : route('partners.index', $company) }}" wire:navigate class="text-gray-500 text-sm hover:underline">Откажи</a>
        </div>
    </form>
</div>
