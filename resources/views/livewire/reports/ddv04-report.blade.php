<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">ДДВ-04 — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="from" value="From" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="To" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </div>

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Промет на добра и услуги</h2>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-1">01 — Оданочив промет по општа даночна стапка (основа)</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['01'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">02 — ДДВ по општа даночна стапка</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['02'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">03 — Оданочив промет по повластена стапка 10% (основа)</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['03'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">04 — ДДВ по повластена стапка 10%</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['04'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">05 — Оданочив промет по повластена стапка 5% (основа)</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['05'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">06 — ДДВ по повластена стапка 5%</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['06'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">07 — Извоз</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['07'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">08 — Промет ослободен со право на одбивка</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['08'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">09 — Промет ослободен без право на одбивка</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['09'], 2) }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">20 — Вкупен ДДВ</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['20'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Влезни исполнувања со право на одбивка</h2>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-1">21 — Влезен промет (основа)</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['21'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">22 — Претходен данок</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['22'], 2) }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">29 — Претходни даноци за одбивање</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['29'], 2) }}</td>
                </tr>
                <tr>
                    <td class="py-1">30 — Останати даноци, претходни даноци и износи за одбивање</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['30'], 2) }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="py-1">31 — Даночен долг / побарување</td>
                    <td class="py-1 text-right font-mono">{{ number_format($fields['31'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
