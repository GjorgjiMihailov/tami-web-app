@props(['padding' => 'p-4'])

<div {{ $attributes->merge(['class' => "bg-white rounded-2xl shadow-card {$padding}"]) }}>
    {{ $slot }}
</div>
