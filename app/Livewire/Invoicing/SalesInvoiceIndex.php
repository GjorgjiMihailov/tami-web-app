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
            ->when(in_array($this->statusFilter, ['draft', 'confirmed', 'cancelled'], true), fn ($q) => $q->where('status', $this->statusFilter))
            ->when(in_array($this->statusFilter, ['unpaid', 'overdue', 'paid'], true), fn ($q) => $q->where('status', 'confirmed'))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        // Платежните филтри зависат од уплатите, па се применуваат врз потврдените.
        // „Доспеани“ = неплатена или делумно платена со поминат рок; „неплатени“ ги
        // вклучува и делумно платените — сè што уште се чека.
        $invoices = match ($this->statusFilter) {
            'paid' => $invoices->filter(fn (SalesInvoice $invoice) => $invoice->paymentStatus() === 'paid'),
            'unpaid' => $invoices->filter(fn (SalesInvoice $invoice) => in_array($invoice->paymentStatus(), ['unpaid', 'partially_paid'], true)),
            'overdue' => $invoices->filter(fn (SalesInvoice $invoice) => $invoice->isOverdue()),
            default => $invoices,
        };

        return view('livewire.invoicing.sales-invoice-index', [
            'invoices' => $invoices->values(),
            // Празната состојба се покажува само кога фирмата нема ниту една фактура,
            // во ниту една година — во празна ГОДИНА се гледа табелата со порака.
            'hasInvoices' => SalesInvoice::where('company_id', $this->company->id)->exists(),
        ]);
    }
}
