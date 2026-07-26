<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">ДДВ-04 — {{ $company->name }}</h1>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="from" value="Од" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="До" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </x-card>

    <x-card class="mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Промет на добра и услуги</h2>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-1">01 — Оданочив промет по општа даночна стапка (основа)</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['01'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">02 — ДДВ по општа даночна стапка</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['02'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">03 — Оданочив промет по повластена стапка 10% (основа)</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['03'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">04 — ДДВ по повластена стапка 10%</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['04'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">05 — Оданочив промет по повластена стапка 5% (основа)</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['05'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">06 — ДДВ по повластена стапка 5%</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['06'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">07 — Извоз</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['07'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">08 — Промет ослободен со право на одбивка</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['08'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">09 — Промет ослободен без право на одбивка</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['09'], currency: '') }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">20 — Вкупен ДДВ</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['20'], currency: '') }}</td>
                </tr>
            </tbody>
        </table>
    </x-card>

    <x-card class="mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Влезни исполнувања со право на одбивка</h2>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-1">21 — Влезен промет (основа)</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['21'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">22 — Претходен данок</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['22'], currency: '') }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">29 — Претходни даноци за одбивање</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['29'], currency: '') }}</td>
                </tr>
                <tr>
                    <td class="py-1">30 — Останати даноци, претходни даноци и износи за одбивање</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['30'], currency: '') }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">31 — Даночен долг / побарување</td>
                    <td class="py-1 text-right font-mono">{{ \App\Support\Format::money($fields['31'], currency: '') }}</td>
                </tr>
            </tbody>
        </table>
    </x-card>
</div>
