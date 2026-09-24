<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Клиенти</h1>
    <p class="text-sm text-gray-500 mb-4">Сите профили во системот. Кликни на клиент за да го отвориш неговиот профил.</p>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('clients.create', 'smetkovoditel') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 bg-brand rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:opacity-90">
            Нов сметководител
        </a>
        <a href="{{ route('clients.create', 'pravno-lice') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 bg-brand rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:opacity-90">
            Ново правно лице
        </a>
        <a href="{{ route('clients.create', 'fizicko-lice') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 bg-brand rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:opacity-90">
            Ново физичко лице
        </a>
    </div>

    <x-card>
        @if ($accountants->isEmpty() && $companies->isEmpty())
            <p class="text-sm text-gray-500">Сè уште нема профили. Создади го првиот со копчињата погоре.</p>
        @else
            <ul class="divide-y divide-gray-200">
                @foreach ($accountants as $accountant)
                    <li class="py-3" wire:key="acc-{{ $accountant->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-800">{{ $accountant->name }}</span>
                            <x-badge status="active">Сметководител</x-badge>
                            @if ($accountant->accessStatus() !== 'active')
                                <x-badge status="pending">{{ $accountant->accessStatus() === 'invited' ? 'Поканет' : 'Не е активен' }}</x-badge>
                            @endif
                        </div>
                        <p class="text-sm text-gray-500 mt-0.5">
                            @if ($accountant->assignedCompanies->isEmpty())
                                Нема доделени клиенти.
                            @else
                                Работи за: {{ $accountant->assignedCompanies->pluck('name')->join(', ') }}
                            @endif
                        </p>
                    </li>
                @endforeach

                @foreach ($companies as $company)
                    @php $account = $accounts->get($company->id); @endphp
                    <li class="py-3" wire:key="co-{{ $company->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('companies.profile', $company) }}" wire:navigate
                               class="font-medium text-brand hover:underline">{{ $company->name }}</a>
                            <x-badge status="active">{{ $company->type->label() }}</x-badge>
                            @if ($account && $account->accessStatus() !== 'active')
                                <x-badge status="pending">{{ $account->accessStatus() === 'invited' ? 'Поканет' : 'Не е активен' }}</x-badge>
                            @endif
                        </div>
                        <p class="text-sm text-gray-500 mt-0.5">
                            @if ($company->accountants->isEmpty())
                                Без сметководител.
                            @else
                                Го води: {{ $company->accountants->pluck('name')->join(', ') }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
