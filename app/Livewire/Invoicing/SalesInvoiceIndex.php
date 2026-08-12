<?php

namespace App\Livewire\Invoicing;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceIndex extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // Scoped on invoice_date, not fiscal_year: fiscal_year is NULL until an
        // invoice is confirmed, so filtering on it would hide every draft.
        // For confirmed invoices the two are identical by construction.
        $invoices = SalesInvoice::where('company_id', $this->company->id)
            ->whereBetween('invoice_date', [$this->workingYearStart(), $this->workingYearEnd()])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.sales-invoice-index', ['invoices' => $invoices]);
    }
}
