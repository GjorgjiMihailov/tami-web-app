<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerShow extends Component
{
    public Company $company;

    public Partner $partner;

    public function mount(Company $company, Partner $partner): void
    {
        Gate::authorize('view', $partner);

        if ($partner->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->partner = $partner;
    }

    public function render()
    {
        return view('livewire.partner-show');
    }
}
