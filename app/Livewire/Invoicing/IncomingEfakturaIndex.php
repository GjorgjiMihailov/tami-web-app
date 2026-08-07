<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class IncomingEfakturaIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $documents = IncomingEfakturaDocument::where('company_id', $this->company->id)
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.incoming-efaktura-index', ['documents' => $documents]);
    }
}
