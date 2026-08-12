<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Аналитичка картица — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="accountId" value="Сметка" />
            <select id="accountId" wire:model.live="accountId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="partnerId" value="Партнер" />
            <select id="partnerId" wire:model.live="partnerId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" value="Од" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="До" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </x-card>

    @if ($accountId || $partnerId)
        <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Датум</th>
                    <th class="py-1 px-3">Опис</th>
                    <th class="py-1 px-3">Партнер</th>
                    <th class="py-1 px-3 text-right">Должи</th>
                    <th class="py-1 px-3 text-right">Побарува</th>
                    <th class="py-1 px-3 text-right">Салдо</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">{{ \App\Support\Format::date($row['date']) }}</td>
                        <td class="py-1 px-3">{{ $row['description'] }}</td>
                        <td class="py-1 px-3">{{ $row['partner'] }}</td>
                        <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($row['debit'], currency: '') }}</td>
                        <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($row['credit'], currency: '') }}</td>
                        <td class="py-1 px-3 text-right">{{ \App\Support\Format::money($row['balance'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-3 text-gray-500">Нема трансакции во овој период.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @else
        <p class="text-gray-500">Изберете сметка и/или партнер за да ја видите аналитичката картица.</p>
    @endif
</div>
