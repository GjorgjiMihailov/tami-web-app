<?php

namespace App\Livewire\Efaktura;

use App\Models\IncomingEfakturaDocument;
use App\Support\CompanyType;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Проверка за нови влезни е-Фактури за сите клиенти на еднаш.
 *
 * Екранот не разговара со УЈП сам — секое барање мора да се потпише со токенот
 * на најавениот корисник, на неговиот компјутер. Затоа проверката ја врти
 * страницата (JavaScript + локалниот потпишувач) фирма по фирма, со истите
 * потпишани чекори како „Провери за е-Фактури“ на влезните фактури. Со PIN што
 * се памти во сесија, PIN се внесува еднаш за сите.
 */
#[Layout('layouts.app')]
class IncomingCheckAll extends Component
{
    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->hasAnyRole(['admin', 'accountant']),
            403
        );
    }

    public function render()
    {
        $user = auth()->user();

        // Опсегот е истиот што го гледа корисникот: сметководител ги гледа само своите клиенти.
        $companies = $user->visibleCompanies()
            ->where('type', CompanyType::LEGAL->value)
            ->where('uses_material', true)
            ->orderBy('name')
            ->get();

        $pending = IncomingEfakturaDocument::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->whereNull('decision')
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        return view('livewire.efaktura.incoming-check-all', [
            'rows' => $companies->map(function ($company) use ($user, $pending) {
                return [
                    'company' => $company,
                    'signer' => $user->can('signEfaktura', $company) ? $user->efakturaSignerFor($company) : null,
                    'pending' => (int) ($pending[$company->id] ?? 0),
                ];
            }),
        ]);
    }
}
