@props(['padding' => 'p-4'])

<div {{ $attributes->merge(['class' => "bg-white rounded-2xl shadow-sm {$padding}"]) }}>
    {{ $slot }}
</div>
