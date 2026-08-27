<?php

namespace App\Livewire;

use App\Models\Company;
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
        return view('livewire.company-dashboard');
    }
}
