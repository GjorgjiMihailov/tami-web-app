<div>
    <a href="{{ route('clients.index') }}" wire:navigate class="text-sm text-brand hover:underline">← Назад на клиенти</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-2 mb-4">{{ $accountant->firm_name ?: $accountant->name }}</h1>

    <x-invite-link-card :link="$inviteLink" :name="$invitedName" :mail-sent="$inviteMailSent" />
    @if ($profileError)
        <p class="mb-4 text-sm text-red-600">{{ $profileError }}</p>
    @endif
    <x-profile-delete-card :deleting="$deleting" />

    <x-card class="max-w-2xl mb-6">
        <dl class="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
            <dt class="text-gray-500">Сметководител</dt>
            <dd>{{ $accountant->name }}</dd>
            @if ($accountant->firm_name)
                <dt class="text-gray-500">Сметководствена фирма</dt>
                <dd>{{ $accountant->firm_name }}</dd>
            @endif
            <dt class="text-gray-500">Е-пошта</dt>
            <dd>{{ $accountant->email }}</dd>
            <dt class="text-gray-500">Состојба</dt>
            <dd>
                @switch($accountant->accessStatus())
                    @case('invited')
                        <x-badge status="pending">Поканет — важи до {{ $accountant->latestInvitation->expires_at->format('d.m.Y') }}</x-badge>
                        @break
                    @case('invitation_expired')
                        <x-badge status="overdue">Поканата истече</x-badge>
                        @break
                    @case('disabled')
                        <x-badge status="overdue">Исклучен</x-badge>
                        @break
                    @default
                        <x-badge status="active">Активен</x-badge>
                @endswitch
            </dd>
            <dt class="text-gray-500">Последна најава</dt>
            <dd>{{ $accountant->last_login_at ? $accountant->last_login_at->format('d.m.Y H:i') : 'Сè уште не се најавил' }}</dd>
            <dt class="text-gray-500">Лимит на фирми</dt>
            <dd>{{ $accountant->company_limit === null ? 'Неограничено' : $accountant->company_limit }}</dd>
        </dl>

        <div class="flex flex-wrap gap-3 mt-5">
            <x-secondary-button type="button" wire:click="resetLink({{ $accountant->id }})">Испрати линк за нова лозинка</x-secondary-button>
            @if ($accountant->disabled_at === null)
                <x-secondary-button type="button" wire:click="disableProfile({{ $accountant->id }})">Исклучи</x-secondary-button>
            @else
                <x-secondary-button type="button" wire:click="enableProfile({{ $accountant->id }})">Вклучи</x-secondary-button>
            @endif
            <x-danger-button type="button" wire:click="requestDelete('accountant', {{ $accountant->id }})">Избриши</x-danger-button>
        </div>
    </x-card>

    <x-card class="max-w-2xl">
        <h2 class="font-semibold text-gray-700 mb-2">Фирми на кои работи</h2>
        @if ($accountant->assignedCompanies->isEmpty())
            <p class="text-sm text-gray-500">Нема доделени фирми.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($accountant->assignedCompanies as $worked)
                    <li class="py-2">
                        <a href="{{ route('companies.profile', $worked) }}" wire:navigate class="text-brand hover:underline">{{ $worked->name }}</a>
                        <span class="text-gray-500">· {{ $worked->type->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
