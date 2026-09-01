<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\CompanyDashboardQuery;
use App\Services\Inventory\StockLevelQuery;
use App\Services\Reports\Ddv04Query;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyDashboard extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);

        if ($this->company->type->isIndividual()) {
            // Само приход и ненаплатено се пресметливи денес. Поднесени/
            // обработени пријави и износот на ДЛД не постојат никаде во
            // апликацијата — доаѓаат со фаза Г (е-ПДД). Види „Dashboard на
            // физичко лице" во design.md: празен екран е полош, а измислени
            // бројки се најлоши, па екранот именува наместо да измисли.
            return view('livewire.company-dashboard', [
                'workingYear' => $year,
                'revenue' => CompanyDashboardQuery::revenue($this->company, $year),
                'receivable' => CompanyDashboardQuery::receivable($this->company, $year),
            ]);
        }

        $revenue = CompanyDashboardQuery::revenue($this->company, $year);
        $costs = CompanyDashboardQuery::costs($this->company, $year);

        // Сметководствената, не јавната сметка на е-Фактура се достапни само
        // за администратор и сметководител — иста поделба како во менито
        // (ФИНАНСИИ) и на самата рута reports.ddv04 (EnsureAccountingAccess).
        // Плочка што води кон екран на кој корисникот и онака ќе добие 403 е
        // полоша од отсутна плочка.
        $canSeeVat = auth()->user()->hasAnyRole(['admin', 'accountant']);

        return view('livewire.company-dashboard', [
            'workingYear' => $year,
            'revenue' => $revenue,
            'costs' => $costs,
            'difference' => bcsub($revenue, $costs, 2),
            'receivable' => CompanyDashboardQuery::receivable($this->company, $year),
            'receivableOverdue' => CompanyDashboardQuery::receivableOverdue($this->company, $year),
            'payable' => CompanyDashboardQuery::payable($this->company, $year),
            'payableOverdue' => CompanyDashboardQuery::payableOverdue($this->company, $year),
            'canSeeVat' => $canSeeVat,
            'vatDue' => $canSeeVat ? $this->vatDue($year) : null,
            'stockValue' => (string) StockLevelQuery::stockOnHandTotals($this->company)->sum('total_value'),
            'efakturaSent' => $this->efakturaSent($year),
            'efakturaFailed' => CompanyDashboardQuery::efakturaFailed($this->company, $year),
        ]);
    }

    /**
     * Истиот период што reports.ddv04 (Ddv04Report) го отвора по подразбирање:
     * ДДВ-04 е месечна пријава, значи тековниот месец кога се работи во
     * тековната година, декември на работната година инаку.
     */
    private function vatDue(int $year): string
    {
        $from = $year === (int) now()->year
            ? now()->startOfMonth()->toDateString()
            : sprintf('%04d-12-01', $year);
        $to = WorkingYear::defaultDate($year);

        $fields = Ddv04Query::run($this->company, Carbon::parse($from), Carbon::parse($to));

        return $fields['31'];
    }

    /**
     * CompanyDashboardQuery::efakturaFailed() брои status=failed. Нема
     * соодветен метод за status=sent зашто Task 7 не го предвиде — ова е
     * прост COUNT на status колона, не сума на пари преку HasInvoiceTotals,
     * па не ја крши истата причина зошто SUM не смее да се дуплира тука.
     */
    private function efakturaSent(int $year): int
    {
        return SalesInvoice::query()
            ->where('company_id', $this->company->id)
            ->whereYear('invoice_date', $year)
            ->where('efaktura_status', 'sent')
            ->count();
    }
}
