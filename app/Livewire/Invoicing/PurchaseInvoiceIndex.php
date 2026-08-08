<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceIndex extends Component
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
        $invoices = PurchaseInvoice::where('company_id', $this->company->id)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments', 'incomingEfakturaDocument'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        $pendingDocuments = IncomingEfakturaDocument::where('company_id', $this->company->id)
            ->where(function ($query) {
                $query->whereNull('decision')
                    ->orWhere(function ($query) {
                        $query->where('decision', IncomingEfakturaDocument::DECISION_REJECTED)
                            ->where('decided_at', '>=', now()->subDays(10));
                    });
            })
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.purchase-invoice-index', [
            'invoices' => $invoices,
            'pendingDocuments' => $pendingDocuments,
        ]);
    }
}
