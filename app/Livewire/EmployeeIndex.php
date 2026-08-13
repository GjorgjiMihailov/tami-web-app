<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeIndex extends Component
{
    public Company $company;

    public bool $showTerminated = false;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);
        $asOf = WorkingYear::defaultDate($year);

        $employees = Employee::where('company_id', $this->company->id)
            ->with('salaries')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->filter(fn (Employee $e) => $this->showTerminated || $e->isActiveOn($asOf))
            ->values();

        return view('livewire.employee-index', [
            'employees' => $employees,
            'asOf' => $asOf,
            'year' => $year,
        ]);
    }
}
