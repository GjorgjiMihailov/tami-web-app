<div class="max-w-xl mx-auto mt-6">
    <x-card padding="p-6">
        <h1 class="text-xl font-bold text-ink">Внесете го вашиот прв клиент</h1>
        <p class="mt-2 text-sm text-stone">
            Сè уште немате ниту една фирма на која работите. Внесете ја првата тука
            заедно со е-поштата на клиентот — тој добива покана за најава — а потоа
            го дополнувате остатокот од податоците на профилот.
        </p>

        <x-invite-link-card :link="$inviteLink" :name="$invitedName" :mail-sent="$inviteMailSent" />
        @if ($createdCompany && $inviteLink)
            <p class="mt-4 text-sm">
                <a href="{{ route('companies.profile', $createdCompany).'?uredi=1' }}" wire:navigate class="text-brand hover:underline">Отвори го профилот и дополни ги податоците →</a>
            </p>
        @endif

        <form wire:submit="save" class="mt-6 space-y-4">
            <div>
                <x-input-label for="first-client-name" value="Име на клиентот" />
                <x-text-input id="first-client-name" wire:model="name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="first-client-type" value="Тип" />
                <select id="first-client-type" wire:model.live="type"
                        class="mt-1 block w-full rounded-lg border-sand text-sm focus:border-brand focus:ring-brand">
                    <option value="">Изберете тип</option>
                    @foreach ($types as $companyType)
                        <option value="{{ $companyType->value }}">{{ $companyType->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('type')" class="mt-2" />
            </div>

            @if ($type === \App\Support\CompanyType::LEGAL->value)
                <div>
                    <x-input-label for="first-client-tax-id" value="ЕДБ" />
                    <x-text-input id="first-client-tax-id" wire:model="taxId" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('taxId')" class="mt-2" />
                </div>
            @endif

            @if ($type === \App\Support\CompanyType::INDIVIDUAL->value)
                <div>
                    <x-input-label for="first-client-embg" value="ЕМБГ" />
                    <x-text-input id="first-client-embg" wire:model="embg" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('embg')" class="mt-2" />
                </div>
            @endif

            @if ($type === \App\Support\CompanyType::LEGAL->value)
                <div>
                    <x-input-label for="first-client-contact" value="Лице за најава (име)" />
                    <x-text-input id="first-client-contact" wire:model="contactName" type="text" class="mt-1 block w-full" />
                </div>
            @endif

            <div>
                <x-input-label for="first-client-email" value="Е-пошта на клиентот (за најава)" />
                <x-text-input id="first-client-email" wire:model="email" type="text" class="mt-1 block w-full" />
                <p class="mt-1 text-xs text-stone">Задолжително. Клиентот добива покана да си постави лозинка и да работи.</p>
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-primary-button class="press">Зачувај и продолжи</x-primary-button>
        </form>
    </x-card>
</div>
