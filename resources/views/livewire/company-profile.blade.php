<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Профил — {{ $company->name }}</h1>
    </div>

    <x-tab-strip :tabs="$tabs" />

    @if ($company->type->isLegal())
        <x-card class="mb-6">
            <h3 class="text-sm font-semibold text-gray-700 mb-1">е-Фактура</h3>
            <p class="text-sm text-gray-600">
                е-Фактури потпишува најавениот корисник со <strong>свој</strong> токен и е-УЈП ID.
                Токенот се регистрира и ажурира во <a href="{{ route('profile') }}" wire:navigate class="text-brand hover:underline">твојот профил</a> —
                овластувањето за оваа фирма се дава во е-УЈП, не тука.
            </p>
        </x-card>
    @endif

    @can('update', $company)
        @if ($editing)
            <x-card class="mb-6">
                <h2 class="font-semibold text-gray-700 mb-3">{{ $company->type->isLegal() ? 'Профил на фирма' : 'Профил на физичко лице' }}</h2>
                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="editName" value="Назив" />
                            <x-text-input id="editName" wire:model="editName" class="w-full" />
                            @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label for="editShortName" value="Кратко име" />
                            <x-text-input id="editShortName" wire:model="editShortName" class="w-full" />
                        </div>
                        @if ($company->type->isLegal())
                            <div>
                                <x-input-label for="editTaxId" value="ЕДБ" />
                                <div class="flex items-center gap-2">
                                    <x-text-input id="editTaxId" wire:model="editTaxId" class="w-full" />
                                    <x-secondary-button type="button" wire:click="checkUjp" wire:loading.attr="disabled" wire:target="checkUjp" class="whitespace-nowrap">
                                        <span wire:loading.remove wire:target="checkUjp">Провери во УЈП</span>
                                        <span wire:loading wire:target="checkUjp">Се проверува…</span>
                                    </x-secondary-button>
                                </div>
                            </div>
                        @endif

                        @if ($ujpLookupError || $ujpLookup)
                            <div class="sm:col-span-2">
                                @if ($ujpLookupError)
                                    <p class="text-red-600 text-sm">{{ $ujpLookupError }}</p>
                                @endif
                                @if ($ujpLookup)
                                    <div class="text-sm bg-orange-50 border border-orange-200 rounded-md p-2 space-y-1">
                                        <p><span class="text-gray-600">УЈП име:</span> {{ $ujpLookup['name'] }}
                                            <button type="button" wire:click="applyUjpName" class="text-brand underline ml-1">Примени</button>
                                        </p>
                                        @if ($ujpLookup['street'] || $ujpLookup['city'])
                                            <p><span class="text-gray-600">УЈП адреса:</span> {{ $ujpLookup['street'] }} {{ $ujpLookup['number'] }}, {{ $ujpLookup['zip'] }} {{ $ujpLookup['city'] }}
                                                <button type="button" wire:click="applyUjpAddress" class="text-brand underline ml-1">Примени</button>
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                        @if ($company->type->isIndividual())
                            <div>
                                <x-input-label for="editEmbg" value="ЕМБГ" />
                                <x-text-input id="editEmbg" wire:model="editEmbg" class="w-full" />
                                @error('editEmbg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        @if ($company->type->isLegal())
                            <div>
                                <x-input-label for="editRegistrationNumber" value="ЕМБС" />
                                <x-text-input id="editRegistrationNumber" wire:model="editRegistrationNumber" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="editNkdCode" value="Шифра на дејност (НКД)" />
                                <x-text-input id="editNkdCode" wire:model="editNkdCode" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="editNkdName" value="Назив на дејност (НКД)" />
                                <x-text-input id="editNkdName" wire:model="editNkdName" class="w-full" />
                            </div>
                        @endif
                        <div>
                            <x-input-label for="editEmail" value="Е-пошта" />
                            <x-text-input id="editEmail" wire:model="editEmail" class="w-full" />
                            @error('editEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label for="editPhone" value="Телефон" />
                            <x-text-input id="editPhone" wire:model="editPhone" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editWebsite" value="Веб-страница" />
                            <x-text-input id="editWebsite" wire:model="editWebsite" class="w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="editAddress" value="Адреса (слободен текст)" />
                            <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editStreetAddress" value="Улица (за е-Фактура)" />
                            <x-text-input id="editStreetAddress" wire:model="editStreetAddress" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editStreetNumber" value="Број" />
                            <x-text-input id="editStreetNumber" wire:model="editStreetNumber" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editPostalCode" value="Поштенски број" />
                            <x-text-input id="editPostalCode" wire:model="editPostalCode" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editCity" value="Град" />
                            <x-text-input id="editCity" wire:model="editCity" class="w-full" />
                        </div>
                        @if ($company->type->isLegal())
                            <div>
                                <x-input-label for="editDirectorName" value="Управител - име" />
                                <x-text-input id="editDirectorName" wire:model="editDirectorName" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="editDirectorEmbg" value="Управител - ЕМБГ" />
                                <x-text-input id="editDirectorEmbg" wire:model="editDirectorEmbg" class="w-full" />
                                @error('editDirectorEmbg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <x-input-label for="editDirectorPhone" value="Управител - телефон" />
                                <x-text-input id="editDirectorPhone" wire:model="editDirectorPhone" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="editDirectorEmail" value="Управител - е-пошта" />
                                <x-text-input id="editDirectorEmail" wire:model="editDirectorEmail" class="w-full" />
                                @error('editDirectorEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Жиро сметки (до 5)</h3>
                        <label class="flex items-center gap-2 text-sm mb-3">
                            <input type="checkbox" wire:model.live="editUsesForeignCurrency">
                            Девизно работење
                        </label>
                        <div class="space-y-2">
                            @foreach ($bankAccounts as $index => $row)
                                <div class="flex flex-wrap gap-3 items-end" wire:key="bank-{{ $index }}">
                                    <div>
                                        <x-input-label for="bank_name_{{ $index }}" value="Назив на банка" />
                                        <x-text-input id="bank_name_{{ $index }}" wire:model="bankAccounts.{{ $index }}.bank_name" class="w-48" />
                                    </div>
                                    <div>
                                        <x-input-label for="account_number_{{ $index }}" value="Број на сметка" />
                                        <x-text-input id="account_number_{{ $index }}" wire:model.live.blur="bankAccounts.{{ $index }}.account_number" class="w-64" />
                                    </div>
                                    @if ($editUsesForeignCurrency)
                                        <div>
                                            <x-input-label for="iban_{{ $index }}" value="IBAN" />
                                            <x-text-input id="iban_{{ $index }}" wire:model="bankAccounts.{{ $index }}.iban" class="w-64" />
                                        </div>
                                        <div>
                                            <x-input-label for="swift_{{ $index }}" value="SWIFT/BIC" />
                                            <x-text-input id="swift_{{ $index }}" wire:model="bankAccounts.{{ $index }}.swift" class="w-40" />
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Лого</h3>
                        <div class="flex flex-wrap gap-4 items-start">
                            <div>
                                @if ($company->logo_path)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) }}" alt="Лого" class="h-16 mb-2">
                                @endif
                                <input type="file" wire:model="newLogo" accept="image/*" class="text-sm">
                                @error('newLogo') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <x-input-label value="Позиција на логото на фактура" />
                                <div class="flex gap-4 text-sm mt-1">
                                    <label class="flex items-center gap-1">
                                        <input type="radio" wire:model="editLogoPosition" value="left"> Лево
                                    </label>
                                    <label class="flex items-center gap-1">
                                        <input type="radio" wire:model="editLogoPosition" value="center"> Средина
                                    </label>
                                    <label class="flex items-center gap-1">
                                        <input type="radio" wire:model="editLogoPosition" value="right"> Десно
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($company->type->isLegal())
                        <div class="space-y-3">
                            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <input type="checkbox" id="editIsVatRegistered" wire:model.live="editIsVatRegistered">
                                Во ДДВ систем (ДДВ обврзник)
                            </label>
                            @if ($editIsVatRegistered)
                                <p class="text-sm text-gray-600">ДДВ број: <span class="font-medium">{{ $editTaxId !== '' ? 'МК'.$editTaxId : 'се формира од ЕДБ' }}</span></p>
                            @endif
                        </div>

                        @if ($company->uses_payroll)
                            <div>
                                <h3 class="text-sm font-semibold text-gray-700 mb-2">Податоци за плата</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label for="editMpinObvrznikCode" value="Вид обврзник (МПИН)" />
                                        <select id="editMpinObvrznikCode" wire:model="editMpinObvrznikCode"
                                                class="border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm transition duration-150 ease-in-out w-full text-sm">
                                            <option value="">— не е одредено —</option>
                                            @foreach (\App\Support\Payroll\MpinObvrznik::cases() as $case)
                                                <option value="{{ $case->value }}">{{ $case->value }} — {{ $case->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('editMpinObvrznikCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <x-code-picker model="editPayrollObligationCode" label="Вид на обврска" :options="$obligations" />
                                    <div>
                                        <x-input-label for="editPayrollAuthorizedPerson" value="Овластено лице" />
                                        <x-text-input id="editPayrollAuthorizedPerson" wire:model="editPayrollAuthorizedPerson" class="w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="editPayrollPhonePrefix" value="Префикс за телефон во градот" />
                                        <x-text-input id="editPayrollPhonePrefix" wire:model="editPayrollPhonePrefix" class="w-24" placeholder="02" />
                                    </div>
                                    <div>
                                        <x-input-label for="editPayrollPhone" value="Телефонски број" />
                                        <x-text-input id="editPayrollPhone" wire:model="editPayrollPhone" class="w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="editPayrollMobile" value="Мобилен број" />
                                        <x-text-input id="editPayrollMobile" wire:model="editPayrollMobile" class="w-full" />
                                    </div>
                                    <x-code-picker model="editPayrollMunicipalityCode" label="Општина" :options="$municipalities" />
                                </div>
                            </div>
                        @endif
                    @endif

                    <div>
                        <x-input-label for="editInvoiceFooterNote" value="Забелешка за фуснота на фактура" />
                        <textarea id="editInvoiceFooterNote" wire:model="editInvoiceFooterNote" rows="3" class="border-gray-300 rounded-md w-full text-sm"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <x-primary-button type="submit">Зачувај</x-primary-button>
                        @if (session('status'))
                            <span class="text-sm text-green-700 self-center">{{ session('status') }}</span>
                        @endif
                    </div>
                </form>
            </x-card>
        @endif
    @endcan
</div>
