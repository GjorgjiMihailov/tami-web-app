<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Services\Accounting\LedgerCardQuery;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class LedgerCardReport extends Component
{
    public Company $company;

    public ?int $accountId = null;

    public ?int $partnerId = null;

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
        $rows = collect();

        if ($this->accountId || $this->partnerId) {
            $rows = LedgerCardQuery::run($this->company, [
                'account_id' => $this->accountId,
                'partner_id' => $this->partnerId,
                'from' => Carbon::parse($this->from),
                'to' => Carbon::parse($this->to),
            ]);
        }

        return view('livewire.accounting.ledger-card-report', [
            'rows' => $rows,
            'accounts' => Account::where('company_id', $this->company->id)->orderBy('code')->get(),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
