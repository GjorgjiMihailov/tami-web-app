<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceShow extends Component
{
    public Company $company;

    public PurchaseInvoice $purchaseInvoice;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentMethod = 'bank';

    public function mount(Company $company, PurchaseInvoice $purchaseInvoice): void
    {
        Gate::authorize('view', $purchaseInvoice);

        if ($purchaseInvoice->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->purchaseInvoice = $purchaseInvoice;
        $this->paymentDate = now()->toDateString();
    }

    public function confirm(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->confirm($this->purchaseInvoice, auth()->id());
        } catch (InsufficientStockException|InvalidInvoiceStateException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->purchaseInvoice->refresh();
    }

    public function cancel(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->cancel($this->purchaseInvoice, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $this->purchaseInvoice->refresh();
    }

    public function recordPayment(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:bank,cash',
        ]);

        try {
            $service->recordPayment($this->purchaseInvoice, $this->paymentAmount, $this->paymentDate, $this->paymentMethod, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->reset(['paymentAmount']);
        $this->purchaseInvoice->refresh();
    }

    public function render()
    {
        $invoice = $this->purchaseInvoice->fresh(['lines.item', 'lines.account', 'payments', 'partner']);

        return view('livewire.invoicing.purchase-invoice-show', [
            'invoice' => $invoice,
        ]);
    }
}
