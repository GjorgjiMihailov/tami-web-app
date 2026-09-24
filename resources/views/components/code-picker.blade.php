@props(['model', 'options', 'label'])

{{-- Поле со шифра од шифрарник: се впишува шифрата (името се појавува само)
     или се бира од листата. Двете полиња се врзани за истото својство. --}}
@php
    $current = (string) $this->{$model};
    $match = $options->firstWhere('code', $current);
@endphp
<div>
    <x-input-label :for="$model" :value="$label" />
    <div class="flex gap-2 mt-1">
        <x-text-input :id="$model" wire:model.live.debounce.400ms="{{ $model }}" class="w-24" placeholder="шифра" />
        <select wire:model.live="{{ $model }}"
                class="border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm text-sm min-w-0 flex-1">
            <option value="">— изберете —</option>
            @foreach ($options as $option)
                <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->name }}</option>
            @endforeach
        </select>
    </div>
    @if ($match)
        <p class="text-xs text-gray-500 mt-1">{{ $match->name }}</p>
    @elseif ($current !== '' && $options->isNotEmpty())
        <p class="text-xs text-red-600 mt-1">Непозната шифра.</p>
    @endif
    @error($model) <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
</div>
