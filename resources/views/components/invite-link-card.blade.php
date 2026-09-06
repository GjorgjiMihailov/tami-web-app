@props(['link', 'name', 'mailSent'])

@if ($link)
    <x-card class="mb-6 border-2 border-brand">
        <h2 class="font-semibold text-gray-700 mb-1">Покана за {{ $name }}</h2>
        <p class="text-sm text-gray-600 mb-2">
            @if ($mailSent)
                Пораката е испратена по е-пошта. Линкот важи {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
            @else
                Пораката не можеше да се испрати по е-пошта. Испратете го линкот рачно — важи
                {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
            @endif
        </p>
        {{-- x-data е потребно за да работат $refs во Alpine. --}}
        <div x-data class="flex gap-2 items-center">
            <input type="text" readonly value="{{ $link }}" x-ref="link"
                   class="flex-1 border-gray-300 rounded-md text-sm bg-gray-50">
            <x-secondary-button type="button"
                                x-on:click="navigator.clipboard.writeText($refs.link.value)">
                Копирај
            </x-secondary-button>
        </div>
        <p class="text-xs text-gray-500 mt-2">Линкот се прикажува само сега. Подоцна се издава нов.</p>
    </x-card>
@endif
