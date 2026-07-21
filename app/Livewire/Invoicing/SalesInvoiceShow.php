<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceShow extends Component
{
    public Company $company;

    public SalesInvoice $salesInvoice;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentMethod = 'bank';

    public function mount(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);

        if ($salesInvoice->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->salesInvoice = $salesInvoice;
        $this->paymentDate = now()->toDateString();
    }

    public function confirm(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        try {
            $service->confirm($this->salesInvoice, auth()->id());
        } catch (InsufficientStockException|InvalidInvoiceStateException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->salesInvoice->refresh();
    }

    public function cancel(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        try {
            $service->cancel($this->salesInvoice, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $this->salesInvoice->refresh();
    }

    public function recordPayment(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:bank,cash',
        ]);

        try {
            $service->recordPayment($this->salesInvoice, $this->paymentAmount, $this->paymentDate, $this->paymentMethod, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->reset(['paymentAmount']);
        $this->salesInvoice->refresh();
    }

    public function markSent(): void
    {
        Gate::authorize('update', $this->salesInvoice);

        if ($this->salesInvoice->status !== 'confirmed') {
            $this->addError('markSent', 'Only confirmed invoices can be marked as sent.');

            return;
        }

        $this->salesInvoice->update(['sent_at' => now()]);
    }

    public function render()
    {
        $invoice = $this->salesInvoice->fresh(['lines.item', 'payments', 'partner']);

        return view('livewire.invoicing.sales-invoice-show', [
            'invoice' => $invoice,
        ]);
    }
}
