<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
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

        // Each row bundles the employee with the salary in force on $asOf,
        // resolved in-memory from the eager-loaded `salaries` relation rather
        // than via Employee::salaryOn() — that method queries the relation
        // method (a fresh query) instead of the loaded collection, which would
        // turn the eager load above into dead weight and cost N extra queries.
        $rows = Employee::where('company_id', $this->company->id)
            ->with('salaries')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->filter(fn (Employee $e) => $this->showTerminated || $e->isActiveOn($asOf))
            ->map(fn (Employee $e) => [
                'employee' => $e,
                'salary' => $e->salaries
                    ->filter(fn (EmployeeSalary $s) => $s->effective_from->toDateString() <= $asOf)
                    ->sortByDesc('effective_from')
                    ->first(),
                'active' => $e->isActiveOn($asOf),
            ])
            ->values();

        return view('livewire.employee-index', [
            'rows' => $rows,
            'asOf' => $asOf,
            'year' => $year,
        ]);
    }
}
