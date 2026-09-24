<div>
    <a href="{{ route('clients.index') }}" wire:navigate class="text-sm text-brand hover:underline">← Назад на клиенти</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-2 mb-4">{{ $title }}</h1>

    <x-invite-link-card :link="$inviteLink" :name="$invitedName" :mail-sent="$inviteMailSent" />

    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-input-label for="name" :value="$kind === 'pravno-lice' ? 'Назив на фирмата' : 'Име и презиме'" />
                <x-text-input id="name" wire:model="name" class="w-full" />
                @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            @if ($kind === 'smetkovoditel')
                <div>
                    <x-input-label for="firmName" value="Назив на сметководствената фирма (по избор)" />
                    <x-text-input id="firmName" wire:model="firmName" class="w-full" />
                    @error('firmName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            @elseif ($kind === 'pravno-lice')
                <div>
                    <x-input-label for="taxId" value="ЕДБ" />
                    <x-text-input id="taxId" wire:model="taxId" class="w-48" />
                    @error('taxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="contactName" value="Име на лицето што ќе се најавува" />
                    <x-text-input id="contactName" wire:model="contactName" class="w-full" />
                    @error('contactName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            @elseif ($kind === 'fizicko-lice')
                <div>
                    <x-input-label for="embg" value="ЕМБГ" />
                    <x-text-input id="embg" wire:model="embg" class="w-48" />
                    @error('embg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            @endif

            <div>
                <x-input-label for="email" value="Е-пошта за најава" />
                <x-text-input id="email" wire:model="email" class="w-full" />
                <p class="text-xs text-gray-500">На оваа адреса се праќа поканата.</p>
                @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            @if ($kind !== 'smetkovoditel')
                <div>
                    <x-input-label for="accountantId" value="Сметководител што го води" />
                    <select id="accountantId" wire:model="accountantId" class="border-gray-300 rounded-md text-sm">
                        <option value="">— без сметководител —</option>
                        @foreach ($accountants as $accountant)
                            <option value="{{ $accountant->id }}">{{ $accountant->name }}</option>
                        @endforeach
                    </select>
                    @error('accountantId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            @endif

            <x-primary-button type="submit">Создади и покани</x-primary-button>
        </form>
    </x-card>
</div>
