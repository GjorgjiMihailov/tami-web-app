<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.partner-index', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
