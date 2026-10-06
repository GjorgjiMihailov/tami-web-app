<?php

namespace App\Livewire\Apps;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Services\Accounting\CashFlowQuery;
use App\Services\Accounting\OpenInvoicesQuery;
use App\Support\CompanyModule;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Влезниот екран на апликацијата Финансии: побарувања, обврски и готовински
 * тек. Побарувањата и обврските се однос со партнери и ги гледа секој што ја
 * гледа фирмата; готовинскиот тек е од книгите, па само админ и сметководител.
 */
#[Layout('layouts.app')]
class FinanceDashboard extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    private function seesBooks(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'accountant']) ?? false;
    }

    /**
     * Менито „+ Нова“ на блокот Побарувања. Ставките се појавуваат само ако
     * корисникот смее да ги направи и фирмата го има потребниот модул.
     *
     * @return list<array{label: string, url: string}>
     */
    public function receivableActions(): array
    {
        $material = $this->company->usesModule(CompanyModule::MATERIAL);
        $actions = [];

        if ($material && Gate::allows('create', SalesInvoice::class)) {
            $actions[] = ['label' => 'Нова фактура', 'url' => route('sales-invoices.create', $this->company)];
        }

        if ($material && Gate::allows('create', ProformaInvoice::class)) {
            $actions[] = ['label' => 'Нова профактура', 'url' => route('proformas.create', $this->company)];
        }

        if ($this->seesBooks()) {
            $actions[] = ['label' => 'Внеси уплата (извод)', 'url' => route('bank-statements.index', $this->company)];
        }

        return $actions;
    }

    /** @return list<array{label: string, url: string}> */
    public function payableActions(): array
    {
        $material = $this->company->usesModule(CompanyModule::MATERIAL);
        $actions = [];

        if ($material && Gate::allows('create', PurchaseInvoice::class)) {
            $actions[] = ['label' => 'Нова влезна фактура', 'url' => route('purchase-invoices.create', $this->company)];
        }

        if ($this->seesBooks()) {
            $actions[] = ['label' => 'Внеси исплата (извод)', 'url' => route('bank-statements.index', $this->company)];
        }

        return $actions;
    }

    /**
     * Ширина на лентата Тековно/Задоцнето во проценти (0–100). Кога нема
     * ништо неплатено лентата е празна, не „100% тековно“.
     *
     * @param  array{total: string, current: string, overdue: string}  $summary
     * @return array{current: int, overdue: int}
     */
    public static function shares(array $summary): array
    {
        if (bccomp($summary['total'], '0', 2) <= 0) {
            return ['current' => 0, 'overdue' => 0];
        }

        $current = (int) round(((float) $summary['current'] / (float) $summary['total']) * 100);

        return ['current' => $current, 'overdue' => 100 - $current];
    }

    public function render()
    {
        $receivables = OpenInvoicesQuery::receivables($this->company);
        $payables = OpenInvoicesQuery::payables($this->company);
        $cashFlow = $this->seesBooks() ? CashFlowQuery::forYear($this->company, $this->workingYear) : null;

        $peak = '0.00';
        if ($cashFlow !== null) {
            foreach ($cashFlow['months'] as $month) {
                foreach ([$month['in'], $month['out']] as $value) {
                    if (bccomp($value, $peak, 2) > 0) {
                        $peak = $value;
                    }
                }
            }
        }

        return view('livewire.apps.finance-dashboard', [
            'receivables' => $receivables,
            'receivableShares' => self::shares($receivables),
            'payables' => $payables,
            'payableShares' => self::shares($payables),
            'cashFlow' => $cashFlow,
            'cashPeak' => $peak,
        ]);
    }
}
