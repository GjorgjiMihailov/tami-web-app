@props(['deleting'])

@if ($deleting)
    <x-card class="mb-6 border-2 border-red-500">
        <h2 class="font-semibold text-red-700 mb-1">Трајно бришење: {{ $deleting['name'] }}</h2>
        <p class="text-sm text-gray-700 mb-3">
            @if ($deleting['kind'] === 'company')
                Со фирмата се бришат и сите нејзини фактури, книжења, документи и сметките за најава. Ова не може да се врати.
            @else
                Се брише сметката на сметководителот. Фирмите што ги работел остануваат. Ова не може да се врати.
            @endif
            Ако само сакаш да го запреш пристапот, користи „Исклучи“.
        </p>
        <form wire:submit="confirmDelete" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[16rem]">
                <x-input-label for="deleteConfirmation" value="За потврда впиши го називот точно како што стои горе" />
                <x-text-input id="deleteConfirmation" wire:model="deleteConfirmation" class="w-full" autocomplete="off" />
                @error('deleteConfirmation') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-danger-button type="submit">Избриши трајно</x-danger-button>
            <button type="button" wire:click="cancelDelete" class="text-sm text-gray-500 hover:underline">Откажи</button>
        </form>
    </x-card>
@endif
