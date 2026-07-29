<div>
    <div class="flex items-center justify-between mb-1">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $partner->name }}</h1>
            <p class="text-sm text-gray-500">{{ $company->name }}</p>
        </div>
        @can('update', $partner)
            @if (! $editing)
                <button type="button" wire:click="startEdit" class="text-brand hover:underline text-sm">Уреди</button>
            @endif
        @endcan
    </div>

    @can('update', $partner)
        @if ($editing)
            <x-card class="mb-4">
                <h2 class="font-semibold text-gray-700 mb-3">Уреди партнер</h2>
                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="editName" value="Назив" />
                            <x-text-input id="editName" wire:model="editName" class="w-full" />
                            @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label for="editType" value="Тип на партнер" />
                            <select id="editType" wire:model.live="editType" class="border-gray-300 rounded-md text-sm w-full">
                                <option value="legal_entity">{{ \App\Support\Format::partnerType('legal_entity') }}</option>
                                <option value="individual">{{ \App\Support\Format::partnerType('individual') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="editTaxId" value="ЕДБ" />
                            <x-text-input id="editTaxId" wire:model="editTaxId" class="w-full" />
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
                        <div class="sm:col-span-2">
                            <x-input-label for="editAddress" value="Адреса" />
                            <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                        </div>

                        @if ($editType === 'legal_entity')
                            <div>
                                <x-input-label for="editRegistrationNumber" value="ЕМБС" />
                                <x-text-input id="editRegistrationNumber" wire:model="editRegistrationNumber" class="w-full" />
                            </div>
                            <div>
                                <x-input-label for="editDirectorName" value="Име на директор" />
                                <x-text-input id="editDirectorName" wire:model="editDirectorName" class="w-full" />
                            </div>
                            <div class="flex items-center gap-2 pb-2">
                                <input type="checkbox" id="editIsVatRegistered" wire:model.live="editIsVatRegistered">
                                <label for="editIsVatRegistered" class="text-sm">Обврзник на ДДВ</label>
                            </div>
                            @if ($editIsVatRegistered)
                                <div>
                                    <x-input-label for="editVatNumber" value="ДДВ-број" />
                                    <x-text-input id="editVatNumber" wire:model="editVatNumber" class="w-full" />
                                </div>
                            @endif
                        @endif
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

                    <div class="flex gap-3">
                        <x-primary-button type="submit">Зачувај</x-primary-button>
                        <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 hover:underline">Откажи</button>
                    </div>
                </form>
            </x-card>
        @endif
    @endcan

    <x-card class="mb-4 text-sm space-y-1">
        <div>Тип: {{ \App\Support\Format::partnerType($partner->type) }}</div>
        <div>ЕДБ: {{ $partner->tax_id ?? '—' }}</div>
        @if ($partner->type === 'legal_entity')
            <div>ЕМБС: {{ $partner->registration_number ?? '—' }}</div>
            <div>Директор: {{ $partner->director_name ?? '—' }}</div>
            <div>Обврзник на ДДВ: {{ $partner->is_vat_registered ? 'Да' : 'Не' }}</div>
            @if ($partner->is_vat_registered)
                <div>ДДВ-број: {{ $partner->vat_number ?? '—' }}</div>
            @endif
        @endif
        <div>Е-пошта: {{ $partner->email ?? '—' }}</div>
        <div>Телефон: {{ $partner->phone ?? '—' }}</div>
        <div>Адреса: {{ $partner->address ?? '—' }}</div>
        <div class="pt-2">
            <div class="font-medium">Трансакциски сметки:</div>
            @forelse ($partner->bankAccounts as $bankAccount)
                <div>{{ $bankAccount->bank_name ? $bankAccount->bank_name.': ' : '' }}{{ $bankAccount->account_number }}</div>
            @empty
                <div>—</div>
            @endforelse
        </div>
    </x-card>

    <livewire:document-manager :documentable="$partner" />
</div>
