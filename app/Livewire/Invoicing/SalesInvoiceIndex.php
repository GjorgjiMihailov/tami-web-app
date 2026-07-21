<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceIndex extends Component
{
    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $invoices = SalesInvoice::where('company_id', $this->company->id)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.sales-invoice-index', ['invoices' => $invoices]);
    }
}
