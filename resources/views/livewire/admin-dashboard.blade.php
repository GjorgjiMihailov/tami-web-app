<div>
    @php
        $roleOf = fn ($user) => $roleLabels[$user->roles->first()?->name] ?? '—';
        $where = fn ($user) => $user->company?->name ?? 'Канцеларија';
    @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-1">Табло</h1>
    <p class="text-sm text-gray-500 mb-6">Преглед на системот. Профили се создаваат и отвораат од „Клиенти".</p>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        <x-card>
            <p class="text-xs text-gray-500">Фирми</p>
            <p class="text-2xl font-bold text-gray-800">{{ $totals['companies'] }}</p>
            <p class="text-xs text-gray-500">правни {{ $totals['legal'] }} · физички {{ $totals['individual'] }}</p>
        </x-card>
        <x-card>
            <p class="text-xs text-gray-500">Сметководители</p>
            <p class="text-2xl font-bold text-gray-800">{{ $totals['accountants'] }}</p>
        </x-card>
        <x-card>
            <p class="text-xs text-gray-500">Администратори</p>
            <p class="text-2xl font-bold text-gray-800">{{ $totals['admins'] }}</p>
        </x-card>
        <x-card>
            <p class="text-xs text-gray-500">Клиентски сметки</p>
            <p class="text-2xl font-bold text-gray-800">{{ $totals['internalClients'] + $totals['freelancers'] }}</p>
            <p class="text-xs text-gray-500">интерно {{ $totals['internalClients'] }} · фриленсери {{ $totals['freelancers'] }}</p>
        </x-card>
        <x-card>
            <p class="text-xs text-gray-500">Бараат внимание</p>
            <p class="text-2xl font-bold {{ $totals['needAttention'] > 0 ? 'text-amber-700' : 'text-gray-800' }}">{{ $totals['needAttention'] }}</p>
            <p class="text-xs text-gray-500">неприфатена покана или исклучена сметка</p>
        </x-card>
    </div>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Сметководители и лимити</h2>
        @if ($accountants->isEmpty())
            <p class="text-sm text-gray-500">Нема сметководители. Создадете го првиот од „Клиенти".</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr class="border-b">
                        <th class="py-1">Име</th>
                        <th class="py-1">Фирми</th>
                        <th class="py-1">Лимит на фирми</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accountants as $accountant)
                        <tr class="border-b last:border-0" wire:key="acc-{{ $accountant->id }}">
                            <td class="py-1">{{ $accountant->name }}</td>
                            <td class="py-1">{{ $accountant->assigned_companies_count }}</td>
                            <td class="py-1">
                                <input type="number" min="0"
                                       wire:change="updateCompanyLimit({{ $accountant->id }}, $event.target.value)"
                                       value="{{ $accountant->company_limit }}"
                                       placeholder="неограничено"
                                       class="w-28 border-gray-300 rounded-md text-sm">
                            </td>
                            <td class="py-1">
                                @if ($accountant->company_limit !== null && $accountant->assigned_companies_count >= $accountant->company_limit)
                                    <x-badge status="pending">Лимитот е достигнат</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Состојба на сметките</h2>
        <div class="flex flex-wrap gap-2 mb-3">
            <x-badge status="active">Активни {{ $statusCounts->get('active', 0) }}</x-badge>
            <x-badge status="pending">Поканети {{ $statusCounts->get('invited', 0) }}</x-badge>
            <x-badge status="overdue">Истечена покана {{ $statusCounts->get('invitation_expired', 0) }}</x-badge>
            <x-badge status="overdue">Исклучени {{ $statusCounts->get('disabled', 0) }}</x-badge>
        </div>

        @if ($needAttention->isEmpty())
            <p class="text-sm text-gray-500">Сите сметки се активни.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr class="border-b">
                        <th class="py-1">Име</th>
                        <th class="py-1">Е-пошта</th>
                        <th class="py-1">Профил</th>
                        <th class="py-1">Фирма</th>
                        <th class="py-1">Состојба</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($needAttention as $account)
                        <tr class="border-b last:border-0" wire:key="att-{{ $account->id }}">
                            <td class="py-1">{{ $account->name }}</td>
                            <td class="py-1">{{ $account->email }}</td>
                            <td class="py-1">{{ $roleOf($account) }}</td>
                            <td class="py-1">{{ $where($account) }}</td>
                            <td class="py-1">
                                @switch($account->accessStatus())
                                    @case('invited')
                                        <x-badge status="pending">Поканет — важи до {{ $account->latestInvitation->expires_at->format('d.m.Y') }}</x-badge>
                                        @break
                                    @case('invitation_expired')
                                        <x-badge status="overdue">Поканата истече</x-badge>
                                        @break
                                    @default
                                        <x-badge status="overdue">Исклучен</x-badge>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <div class="grid lg:grid-cols-2 gap-6">
        <x-card>
            <h2 class="font-semibold text-gray-700 mb-2">Активни сега</h2>
            <p class="text-xs text-gray-500 mb-2">Профили што работеле во последните {{ $activeWindowHours }} часа.</p>
            @if (! $sessionsAvailable)
                <p class="text-sm text-gray-500">Активните сесии не се чуваат во база, па оваа листа не може да се прикаже.</p>
            @elseif ($activeNow->isEmpty())
                <p class="text-sm text-gray-500">Никој не е активен во моментов.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($activeNow as $person)
                        <li class="py-1 flex justify-between gap-3" wire:key="act-{{ $person->id }}">
                            <span>{{ $person->name }} <span class="text-gray-500">· {{ $roleOf($person) }} · {{ $where($person) }}</span></span>
                            <span class="text-gray-500">{{ \Illuminate\Support\Carbon::createFromTimestamp($lastSeen[$person->id])->format('H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card>
            <h2 class="font-semibold text-gray-700 mb-2">Последни најави</h2>
            @if ($recentLogins->isEmpty())
                <p class="text-sm text-gray-500">Најавите се бележат од денес. Податок ќе се појави штом некој се најави.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($recentLogins as $person)
                        <li class="py-1 flex justify-between gap-3" wire:key="login-{{ $person->id }}">
                            <span>{{ $person->name }} <span class="text-gray-500">· {{ $roleOf($person) }} · {{ $where($person) }}</span></span>
                            <span class="text-gray-500">{{ $person->last_login_at->format('d.m.Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</div>
