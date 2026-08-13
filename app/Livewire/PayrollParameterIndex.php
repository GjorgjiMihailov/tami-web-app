<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\PayrollParameter;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollParameterIndex extends Component
{
    public Company $company;

    public string $effectiveFrom = '';

    public string $ratePension = '';

    public string $rateHealth = '7.5';

    public string $rateInjury = '0.5';

    public string $rateUnemployment = '';

    public string $rateTax = '10';

    public string $personalAllowance = '';

    public string $averageSalary = '';

    public string $minBase = '';

    public string $maxBase = '';

    public string $minimumWage = '';

    public function mount(Company $company): void
    {
        // Shared state parameters: a wrong value here breaks every company's
        // calculation, so this is admin-only and enforced on the server.
        abort_unless(auth()->user()->hasRole('admin'), 403);

        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function addPeriod(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $validated = $this->validate([
            'effectiveFrom' => 'required|date|unique:payroll_parameters,effective_from',
            'ratePension' => 'required|numeric|min:0|max:100',
            'rateHealth' => 'required|numeric|min:0|max:100',
            'rateInjury' => 'required|numeric|min:0|max:100',
            'rateUnemployment' => 'required|numeric|min:0|max:100',
            'rateTax' => 'required|numeric|min:0|max:100',
            'personalAllowance' => 'required|numeric|min:0',
            'averageSalary' => 'required|numeric|min:0',
            'minBase' => 'required|numeric|min:0',
            'maxBase' => 'required|numeric|min:0',
            'minimumWage' => 'required|numeric|min:0',
        ]);

        PayrollParameter::create([
            'effective_from' => $validated['effectiveFrom'],
            'rate_pension' => $validated['ratePension'],
            'rate_health' => $validated['rateHealth'],
            'rate_injury' => $validated['rateInjury'],
            'rate_unemployment' => $validated['rateUnemployment'],
            'rate_tax' => $validated['rateTax'],
            'personal_allowance' => $validated['personalAllowance'],
            'average_salary' => $validated['averageSalary'],
            'min_base' => $validated['minBase'],
            'max_base' => $validated['maxBase'],
            'minimum_wage' => $validated['minimumWage'],
        ]);

        $this->reset([
            'effectiveFrom', 'ratePension', 'rateUnemployment', 'personalAllowance',
            'averageSalary', 'minBase', 'maxBase', 'minimumWage',
        ]);
    }

    public function render()
    {
        return view('livewire.payroll-parameter-index', [
            'parameters' => PayrollParameter::orderByDesc('effective_from')->get(),
        ]);
    }
}
