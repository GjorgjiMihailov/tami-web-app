<?php

namespace App\Livewire\Efaktura;

use App\Models\SalesInvoice;
use App\Support\CompanyType;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Работниот список на канцеларијата: потврдени излезни фактури што сè уште не
 * се пратени до УЈП, од сите нејзини клиенти, на едно место.
 *
 * Екранот не праќа ништо. Праќањето бара физички USB токен, па се прави од
 * самата фактура — на компјутерот каде е токенот. Списокот само кажува што
 * чека, најстарата прва, и дали за таа фирма воопшто е запишан токен.
 *
 * Фактура во странска валута се изоставува: е-Фактура прима само денарски
 * износи, па таа не чека на праќање, туку не може да се прати.
 */
#[Layout('layouts.app')]
class PendingSendList extends Component
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
        // Опсегот е истиот што го гледа корисникот преку visibleCompanies():
        // сметководител ги гледа само своите клиенти.
        $companies = auth()->user()->visibleCompanies()
            ->where('type', CompanyType::LEGAL->value)
            ->where('uses_material', true)
            ->select('id');

        return view('livewire.efaktura.pending-send-list', [
            'invoices' => SalesInvoice::query()
                ->whereIn('company_id', $companies)
                ->where('status', 'confirmed')
                // not_sent и failed чекаат; sent е готово. Колоната е NOT NULL со
                // стандардна вредност not_sent, па „непратена" не е null.
                ->where('efaktura_status', '!=', 'sent')
                ->where('currency', 'MKD')
                ->with(['company', 'partner', 'lines'])
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->get(),
        ]);
    }
}
