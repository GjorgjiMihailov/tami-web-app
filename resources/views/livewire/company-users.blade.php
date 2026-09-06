<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ $company->name }}</h1>

    <x-tab-strip :tabs="$tabs" />

    <x-invite-link-card :link="$inviteLink" :name="$invitedName" :mail-sent="$inviteMailSent" />

    @can('create', \App\Models\User::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Отвори сметка</h2>
            <form wire:submit="addUser" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[14rem]">
                    <x-input-label for="newName" value="Име и презиме" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[14rem]">
                    <x-input-label for="newEmail" value="Е-пошта" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-full" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button>Отвори и покани</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-2">Сметки на оваа фирма</h2>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr class="border-b">
                    <th class="py-1">Име</th>
                    <th class="py-1">Е-пошта</th>
                    <th class="py-1">Состојба</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="border-b last:border-0">
                        <td class="py-1">{{ $user->name }}</td>
                        <td class="py-1">{{ $user->email }}</td>
                        <td class="py-1">
                            @switch($user->accessStatus())
                                @case('invited')
                                    <x-badge status="pending">Поканет — важи до
                                        {{ $user->latestInvitation->expires_at->format('d.m.Y') }}</x-badge>
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
                        </td>
                        <td class="py-1 text-right">
                            @can('disable', $user)
                                <div class="flex gap-2 justify-end">
                                    @if ($user->disabled_at === null)
                                        <button type="button" wire:click="reinvite({{ $user->id }})"
                                                class="text-brand hover:underline">Издај нова покана</button>
                                        <button type="button" wire:click="disable({{ $user->id }})"
                                                class="text-red-600 hover:underline">Исклучи пристап</button>
                                    @else
                                        <button type="button" wire:click="enable({{ $user->id }})"
                                                class="text-brand hover:underline">Врати пристап</button>
                                    @endif
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Нема отворени сметки за оваа фирма.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-card class="mt-6">
        <h2 class="font-semibold text-gray-700 mb-2">Сметководители на оваа фирма</h2>

        <ul class="text-sm divide-y">
            @forelse ($assigned as $accountant)
                <li class="py-1 flex justify-between items-center">
                    <span>{{ $accountant->name }} <span class="text-gray-500">({{ $accountant->email }})</span></span>
                    @can('create', \App\Models\User::class)
                        <button type="button" wire:click="removeAccountant({{ $accountant->id }})"
                                class="text-red-600 hover:underline">Тргни</button>
                    @endcan
                </li>
            @empty
                <li class="py-1 text-gray-500">Нема доделен сметководител.</li>
            @endforelse
        </ul>

        @can('create', \App\Models\User::class)
            @if ($available->isNotEmpty())
                <div class="mt-3 flex gap-2 items-center">
                    <select wire:model="accountantToAssign" class="border-gray-300 rounded-md text-sm">
                        <option value="">— изберете —</option>
                        @foreach ($available as $accountant)
                            <option value="{{ $accountant->id }}">{{ $accountant->name }}</option>
                        @endforeach
                    </select>
                    {{-- Само еден начин на повикување: бројот доаѓа од
                         својството, не од Blade израз. --}}
                    <div x-data>
                        <x-secondary-button type="button"
                                            x-bind:disabled="! $wire.accountantToAssign"
                                            x-on:click="$wire.assignAccountant($wire.accountantToAssign)">
                            Додели
                        </x-secondary-button>
                    </div>
                </div>
            @endif
        @endcan
    </x-card>
</div>
