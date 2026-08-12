<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Services\Accounting\TrialBalanceQuery;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TrialBalanceReport extends Component
{
    public Company $company;

    public string $groupBy = 'account';

    public string $from = '';

    public string $to = '';

    public int $workingYear = 0;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
        $this->from = WorkingYear::startOf($this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }

    public function render()
    {
        $rows = TrialBalanceQuery::run($this->company, $this->groupBy, Carbon::parse($this->from), Carbon::parse($this->to));

        $totals = [
            'opening_balance' => $rows->sum('opening_balance'),
            'movement_debit' => $rows->sum('movement_debit'),
            'movement_credit' => $rows->sum('movement_credit'),
            'closing_balance' => $rows->sum('closing_balance'),
        ];

        return view('livewire.accounting.trial-balance-report', ['rows' => $rows, 'totals' => $totals]);
    }
}
