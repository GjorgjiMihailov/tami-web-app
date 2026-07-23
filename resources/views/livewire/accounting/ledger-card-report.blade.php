<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Аналитичка картица — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="accountId" value="Account" />
            <select id="accountId" wire:model.live="accountId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="partnerId" value="Partner" />
            <select id="partnerId" wire:model.live="partnerId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" value="From" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="To" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </x-card>

    @if ($accountId || $partnerId)
        <x-card class="p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Date</th>
                    <th class="py-2 px-4">Description</th>
                    <th class="py-2 px-4">Partner</th>
                    <th class="py-2 px-4 text-right">Debit</th>
                    <th class="py-2 px-4 text-right">Credit</th>
                    <th class="py-2 px-4 text-right">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}</td>
                        <td class="py-2 px-4">{{ $row['description'] }}</td>
                        <td class="py-2 px-4">{{ $row['partner'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['debit'], 2) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['credit'], 2) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">No transactions in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @else
        <p class="text-gray-500">Select an account and/or a partner to see the ledger card.</p>
    @endif
</div>
