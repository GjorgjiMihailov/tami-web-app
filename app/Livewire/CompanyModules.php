<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CompanyTabs;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Што користи клиентот. Извадено од CompanyProfile: тоа е одлука на
 * канцеларијата за опфатот на услугата, не податок за фирмата, а и единственото
 * во таа форма што го менува менито.
 */
#[Layout('layouts.app')]
class CompanyModules extends Component
{
    public Company $company;

    public bool $usesMaterial = true;

    public bool $usesStock = true;

    public bool $usesPayroll = true;

    public bool $usesFinance = true;

    public bool $saved = false;

    public function mount(Company $company): void
    {
        // Модулите ги менува само админ — истото правило како за профилот.
        Gate::authorize('update', $company);

        $this->company = $company;
        $this->usesMaterial = $company->uses_material;
        $this->usesStock = $company->uses_stock;
        $this->usesPayroll = $company->uses_payroll;
        $this->usesFinance = $company->uses_finance;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'usesMaterial' => 'boolean',
            'usesStock' => 'boolean',
            'usesPayroll' => 'boolean',
            'usesFinance' => 'boolean',
        ]);

        $this->company->forceFill([
            'uses_material' => $validated['usesMaterial'],
            // Залиха без Материјално не постои — истото правило како при
            // создавање фирма.
            'uses_stock' => $validated['usesMaterial'] && $validated['usesStock'],
            'uses_payroll' => $validated['usesPayroll'],
            'uses_finance' => $validated['usesFinance'],
        ])->save();

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.company-modules', [
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.modules'),
        ]);
    }
}
