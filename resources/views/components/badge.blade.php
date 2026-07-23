@props(['status'])

@php
$classes = match ($status) {
    'confirmed', 'paid', 'active' => 'bg-green-100 text-green-800',
    'draft', 'pending', 'unpaid' => 'bg-amber-100 text-amber-800',
    'cancelled', 'overdue' => 'bg-red-100 text-red-800',
    default => 'bg-gray-100 text-gray-700',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot }}
</span>
