<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Бруто Биланс — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="groupBy" value="Group by" />
            <select id="groupBy" wire:model.live="groupBy" class="border-gray-300 rounded-md text-sm">
                <option value="account">Full account (по конта)</option>
                <option value="synthetic">Synthetic account only (по синтетики)</option>
                <option value="partner">Partner (по фирми)</option>
                <option value="account_partner">Account + partner (Кумулатив по аналитички конта и фирми)</option>
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

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Code</th>
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4 text-right">Opening balance</th>
                <th class="py-2 px-4 text-right">Movement debit</th>
                <th class="py-2 px-4 text-right">Movement credit</th>
                <th class="py-2 px-4 text-right">Closing balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $row['key'] }}</td>
                    <td class="py-2 px-4">{{ $row['label'] }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['opening_balance'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['movement_debit'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['movement_credit'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['closing_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">No activity in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
