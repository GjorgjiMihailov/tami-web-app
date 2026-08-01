<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold text-gray-800">Работите на: {{ $company->name }}</h1>
        @can('update', $company)
            @if (! $editing)
                <button type="button" wire:click="startEdit" class="text-brand hover:underline text-sm">Уреди</button>
            @endif
        @endcan
    </div>
    <p class="text-sm text-gray-500 mb-6">Изберете модул подолу за да започнете.</p>

    @if ($company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_FIRM)
        <x-card class="mb-6">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">е-Фактура пристап</h3>
            @if (in_array($company->efaktura_firm_access_status, [
                    \App\Models\Company::EFAKTURA_STATUS_NONE,
                    \App\Models\Company::EFAKTURA_STATUS_REJECTED,
                ]))
                <button wire:click="requestFirmEfakturaAccess" type="button" class="rounded-full bg-orange-600 text-white px-4 py-2 text-sm">
                    Побарај користење на фирмениот сертификат
                </button>
            @elseif ($company->efaktura_firm_access_status === \App\Models\Company::EFAKTURA_STATUS_REQUESTED)
                <x-badge status="pending">Чека одобрување</x-badge>
            @elseif ($company->efaktura_firm_access_status === \App\Models\Company::EFAKTURA_STATUS_APPROVED)
                <x-badge status="active">Одобрено</x-badge>
            @endif
        </x-card>
    @endif

    @if (auth()->user()->hasAnyRole(['admin', 'accountant']))
        <x-card class="mb-6" x-data="signingDeviceRegistration()">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Потпишувачки уред (USB токен)</h3>

            @if ($company->efaktura_token_serial_number)
                <p class="text-sm text-gray-600 mb-1">Регистриран: <span class="font-medium">{{ $company->efaktura_token_subject_name }}</span> (сериски бр. {{ $company->efaktura_token_serial_number }})</p>
                <p class="text-xs text-gray-500 mb-3">Важи до {{ optional($company->efaktura_token_not_after)->format('d.m.Y') }}</p>
            @else
                <p class="text-sm text-gray-500 mb-3">Нема регистриран потпишувачки уред за оваа компанија.</p>
            @endif

            <div class="flex items-center gap-3">
                <button type="button" @click="check()" :disabled="busy" class="rounded-full bg-gray-100 text-gray-700 px-4 py-2 text-sm disabled:opacity-50">
                    <span x-show="!busy">Провери токен</span>
                    <span x-show="busy">Читам...</span>
                </button>
                <a href="{{ asset('downloads/efaktura-bridge/EfakturaBridge.Server.exe') }}" class="text-brand hover:underline text-sm">Преземи локален потпишувач</a>
            </div>

            <div x-show="detected" class="mt-3 border rounded-lg p-3 bg-gray-50">
                <p class="text-sm">Пронајден: <span x-text="subjectName" class="font-medium"></span></p>
                <p class="text-xs text-gray-500">Сериски бр. <span x-text="serialNumber"></span>, важи до <span x-text="notAfter"></span></p>
                <button type="button" @click="confirmRegister()" class="mt-2 rounded-full bg-brand text-white px-4 py-1.5 text-sm">Потврди — ова е точниот уред</button>
            </div>

            <p x-show="error" x-text="error" class="text-red-600 text-sm mt-2"></p>
        </x-card>

        @script
        <script>
            Alpine.data('signingDeviceRegistration', () => ({
                busy: false,
                detected: false,
                error: '',
                serialNumber: '',
                subjectName: '',
                notBefore: '',
                notAfter: '',
                async check() {
                    this.busy = true; this.error = ''; this.detected = false;
                    try {
                        const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                        if (!health || !health.ok) {
                            throw new Error('Локалниот потпишувач не работи. Стартувај го (преземи го копчето погоре) и обиди се повторно.');
                        }
                        const certRes = await fetch('http://127.0.0.1:9847/certificate');
                        if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот — провери дали е приклучен.');
                        const cert = await certRes.json();
                        this.serialNumber = cert.serialNumber;
                        this.subjectName = cert.subjectName;
                        this.notBefore = cert.notBefore;
                        this.notAfter = cert.notAfter;
                        this.detected = true;
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.busy = false;
                    }
                },
                async confirmRegister() {
                    await $wire.registerSigningDevice(this.serialNumber, this.subjectName, this.notBefore, this.notAfter);
                    this.detected = false;
                },
            }));
        </script>
        @endscript
    @endif

    @can('update', $company)
        @if ($editing)
            <x-card class="mb-6">
                <h2 class="font-semibold text-gray-700 mb-3">Профил на фирма</h2>
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
                        <div>
                            <x-input-label for="editTaxId" value="ЕДБ" />
                            <x-text-input id="editTaxId" wire:model="editTaxId" class="w-full" />
                        </div>
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
                        <div>
                            <x-input-label for="editDirectorName" value="Управител - име" />
                            <x-text-input id="editDirectorName" wire:model="editDirectorName" class="w-full" />
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
                        <div class="flex items-center gap-2 pb-2">
                            <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                            <label for="editIsVatRegistered" class="text-sm">Во ДДВ систем</label>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Трансакциски сметки (до 5)</h3>
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

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">е-Фактура акредитиви</h3>
                        <div class="flex gap-4 mb-3">
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="editEfakturaMode" value="firm">
                                <span>Користи го фирменото</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="editEfakturaMode" value="own">
                                <span>Сопствени акредитиви</span>
                            </label>
                        </div>

                        @if ($editEfakturaMode === 'own')
                            <div>
                                <label class="block text-sm text-gray-600 mb-1">X-EUJP-ID</label>
                                <input type="text" wire:model="editEfakturaEujpId" class="w-full rounded-lg border-gray-300">
                                @error('editEfakturaEujpId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                <p class="text-xs text-gray-500 mt-1">Потпишувачкиот уред (USB токен) се регистрира одделно, подолу на страницата — не преку овој формулар.</p>
                            </div>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="editInvoiceFooterNote" value="Забелешка за фуснота на фактура" />
                        <textarea id="editInvoiceFooterNote" wire:model="editInvoiceFooterNote" rows="3" class="border-gray-300 rounded-md w-full text-sm"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <x-primary-button type="submit">Зачувај</x-primary-button>
                        <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 hover:underline">Откажи</button>
                    </div>
                </form>
            </x-card>
        @endif
    @endcan

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Контен план, налози, картици, биланс</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Магацини, артикли, извештаи за залихи</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Партнери, излезни и влезни фактури</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Прикачени и генерирани документи</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Законски извештаи</p>
            </x-card>
        </a>
    </div>
</div>
