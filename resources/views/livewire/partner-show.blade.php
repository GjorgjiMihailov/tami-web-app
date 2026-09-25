<div>
    <div class="flex items-center justify-between mb-1">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $partner->name }}</h1>
            <p class="text-sm text-gray-500">{{ $company->name }}</p>
        </div>
        @can('update', $partner)
            <a href="{{ route('partners.edit', [$company, $partner]) }}" wire:navigate class="text-brand hover:underline text-sm">Уреди</a>
        @endcan
    </div>

    <x-card class="mb-4 text-sm space-y-1">
        <div>Тип: {{ \App\Support\Format::partnerType($partner->type) }}</div>
        @if ($partner->contact_first_name || $partner->contact_last_name)
            <div>Примарен контакт: {{ trim(implode(' ', array_filter([$partner->contact_salutation, $partner->contact_first_name, $partner->contact_last_name]))) }}</div>
        @endif
        <div>ЕДБ: {{ $partner->tax_id ?? '—' }}</div>
        @if ($partner->type === 'legal_entity')
            <div>ЕМБС: {{ $partner->registration_number ?? '—' }}</div>
            <div>Директор: {{ $partner->director_name ?? '—' }}</div>
            <div>Обврзник на ДДВ: {{ $partner->is_vat_registered ? 'Да' : 'Не' }}</div>
            @if ($partner->is_vat_registered)
                <div>ДДВ-број: {{ $partner->vat_number ?? '—' }}</div>
            @endif
        @endif
        <div>Е-пошта: {{ $partner->email ?? '—' }}</div>
        <div>Телефон: {{ $partner->phone ?? '—' }}</div>
        @if ($partner->mobile)
            <div>Мобилен: {{ $partner->mobile }}</div>
        @endif
        <div>Адреса: {{ $partner->printedAddress() ?? '—' }}</div>
        @if ($partner->shipping_street_address || $partner->shipping_city)
            <div>Адреса за испорака: {{ trim(implode(', ', array_filter([
                trim($partner->shipping_street_address.' '.$partner->shipping_street_number),
                trim($partner->shipping_postal_code.' '.$partner->shipping_city),
                $partner->shipping_country,
            ]))) }}</div>
        @endif
        @if ($partner->payment_terms_days !== null)
            <div>Рок на плаќање: {{ $partner->payment_terms_days === 0 ? 'По приемот' : $partner->payment_terms_days.' дена' }}</div>
        @endif
        <div class="pt-2">
            <div class="font-medium">Трансакциски сметки:</div>
            @forelse ($partner->bankAccounts as $bankAccount)
                <div>{{ $bankAccount->bank_name ? $bankAccount->bank_name.': ' : '' }}{{ $bankAccount->account_number }}</div>
            @empty
                <div>—</div>
            @endforelse
        </div>
        @if ($partner->contacts->isNotEmpty())
            <div class="pt-2">
                <div class="font-medium">Контакт лица:</div>
                @foreach ($partner->contacts as $contact)
                    <div>{{ $contact->fullName() }}@if ($contact->email) · {{ $contact->email }}@endif @if ($contact->phone) · {{ $contact->phone }}@endif @if ($contact->mobile) · {{ $contact->mobile }}@endif</div>
                @endforeach
            </div>
        @endif
    </x-card>

    <livewire:document-manager :documentable="$partner" />
</div>
