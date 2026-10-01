<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Support\WorkingYear;
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

    public int $workingYear = 0;

    public bool $editingNumber = false;

    public string $newNumber = '';

    public function mount(Company $company, PurchaseInvoice $purchaseInvoice): void
    {
        Gate::authorize('view', $purchaseInvoice);

        if ($purchaseInvoice->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
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

    public function delete(PurchaseInvoiceService $service)
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->delete($this->purchaseInvoice, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('delete', $e->getMessage());

            return;
        }

        return $this->redirect(route('purchase-invoices.index', $this->company), navigate: true);
    }

    public function startEditingNumber(): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        $this->newNumber = (string) $this->purchaseInvoice->supplier_invoice_number;
        $this->editingNumber = true;
    }

    public function cancelEditingNumber(): void
    {
        $this->editingNumber = false;
        $this->resetErrorBag('newNumber');
    }

    public function changeNumber(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->changeSupplierNumber($this->purchaseInvoice, $this->newNumber, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('newNumber', $e->getMessage());

            return;
        }

        $this->editingNumber = false;
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
        $invoice = $this->purchaseInvoice->fresh(['lines.item', 'lines.account', 'payments', 'partner', 'incomingEfakturaDocument']);

        // Левата листа ја покажува работната година, но секогаш ја вклучува и
        // отворената фактура (може да е од друга година).
        $sidebar = \App\Models\PurchaseInvoice::where('company_id', $this->company->id)
            ->where(fn ($q) => $q
                ->whereBetween('invoice_date', [\App\Support\WorkingYear::startOf($this->workingYear), \App\Support\WorkingYear::endOf($this->workingYear)])
                ->orWhere('id', $invoice->id))
            ->with(['partner:id,name', 'lines', 'payments'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('livewire.invoicing.purchase-invoice-show', [
            'invoice' => $invoice,
            'sidebar' => $sidebar,
        ]);
    }
}
