<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeForm extends Component
{
    public Company $company;

    public ?Employee $employee = null;

    public function mount(Company $company, ?Employee $employee = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->employee = $employee;
    }

    public function render()
    {
        return view('livewire.employee-form');
    }
}
