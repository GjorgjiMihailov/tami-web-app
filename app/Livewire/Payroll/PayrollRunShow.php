<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollRunShow extends Component
{
    public Company $company;

    public PayrollRun $run;

    public function mount(Company $company, PayrollRun $run): void
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $this->company = $company;
        $this->run = $run;
    }

    public function render()
    {
        return view('livewire.payroll.payroll-run-show');
    }
}
