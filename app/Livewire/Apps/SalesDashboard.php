<?php

namespace App\Livewire\Apps;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Влезниот екран на апликацијата Продажба.
 *
 * Порано кликот на „Продажба" паѓаше право во Излезни фактури, зашто
 * AppSwitcher ја земаше буквално првата ставка од менито.
 */
#[Layout('layouts.app')]
class SalesDashboard extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        return view('livewire.apps.sales-dashboard');
    }
}
