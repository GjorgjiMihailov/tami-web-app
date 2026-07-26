<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">{{ $partner->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">{{ $company->name }}</p>

    <x-card class="mb-4 text-sm space-y-1">
        <div>ЕДБ: {{ $partner->tax_id ?? '—' }}</div>
        <div>Е-пошта: {{ $partner->email ?? '—' }}</div>
        <div>Телефон: {{ $partner->phone ?? '—' }}</div>
        <div>Адреса: {{ $partner->address ?? '—' }}</div>
    </x-card>

    <livewire:document-manager :documentable="$partner" />
</div>
