<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Services\Accounting\TrialBalanceQuery;
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

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $rows = TrialBalanceQuery::run($this->company, $this->groupBy, Carbon::parse($this->from), Carbon::parse($this->to));

        return view('livewire.accounting.trial-balance-report', ['rows' => $rows]);
    }
}
