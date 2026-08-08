<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Бруто Биланс — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="groupBy" value="Групирај по" />
            <select id="groupBy" wire:model.live="groupBy" class="border-gray-300 rounded-md text-sm">
                <option value="account">По аналитички конта</option>
                <option value="synthetic">По синтетички конта</option>
                <option value="partner">По партнери</option>
                <option value="account_partner">Кумулатив по аналитички конта и партнери</option>
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

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4 text-right">Почетно салдо</th>
                <th class="py-2 px-4 text-right">Промет должи</th>
                <th class="py-2 px-4 text-right">Промет побарува</th>
                <th class="py-2 px-4 text-right">Крајно салдо</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4 font-mono">{{ $row['key'] }}</td>
                    <td class="py-2 px-4">{{ $row['label'] }}</td>
                    <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['opening_balance'], currency: '') }}</td>
                    <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['movement_debit'], currency: '') }}</td>
                    <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['movement_credit'], currency: '') }}</td>
                    <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['closing_balance'], currency: '') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема промет во овој период.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="text-sm font-bold border-t border-gray-300 bg-gray-50">
                <td class="py-2 px-4" colspan="2">Вкупно</td>
                <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($totals['opening_balance'], currency: '') }}</td>
                <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($totals['movement_debit'], currency: '') }}</td>
                <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($totals['movement_credit'], currency: '') }}</td>
                <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($totals['closing_balance'], currency: '') }}</td>
            </tr>
        </tfoot>
    </table>
    </x-card>
</div>
