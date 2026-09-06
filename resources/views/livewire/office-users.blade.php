<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Фирми</h1>

    <x-tab-strip :tabs="[
        ['label' => 'Клиенти', 'url' => route('companies.index'), 'active' => false],
        ['label' => 'Канцеларија', 'url' => route('companies.office'), 'active' => true],
    ]" />

    <x-invite-link-card :link="$inviteLink" :name="$invitedName" :mail-sent="$inviteMailSent" />

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
            <div>
                <x-input-label for="newRole" value="Улога" />
                <select id="newRole" wire:model="newRole" class="border-gray-300 rounded-md text-sm">
                    @foreach (\App\Livewire\OfficeUsers::ROLES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('newRole') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-primary-button>Отвори и покани</x-primary-button>
        </form>
    </x-card>

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-2">Сметки на канцеларијата</h2>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr class="border-b">
                    <th class="py-1">Име</th>
                    <th class="py-1">Е-пошта</th>
                    <th class="py-1">Улога</th>
                    <th class="py-1">Состојба</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b last:border-0">
                        <td class="py-1">{{ $user->name }}</td>
                        <td class="py-1">{{ $user->email }}</td>
                        <td class="py-1">
                            {{ \App\Livewire\OfficeUsers::ROLES[$user->roles->first()?->name] ?? '' }}
                        </td>
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
                                    <button type="button" wire:click="reinvite({{ $user->id }})"
                                            class="text-brand hover:underline">Издај нова покана</button>
                                    @if ($user->disabled_at === null)
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
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
