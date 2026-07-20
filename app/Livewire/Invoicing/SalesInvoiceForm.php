<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceForm extends Component
{
    public Company $company;

    public ?SalesInvoice $salesInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    public array $lines = [];

    public function mount(Company $company, ?SalesInvoice $salesInvoice = null): void
    {
        $this->company = $company;

        Gate::authorize($salesInvoice ? 'update' : 'create', $salesInvoice ?? SalesInvoice::class);

        if ($salesInvoice) {
            if ($salesInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($salesInvoice->status !== 'draft') {
                abort(403, 'Only draft invoices can be edited.');
            }
        }

        $this->salesInvoice = $salesInvoice;

        if ($salesInvoice) {
            $this->partnerId = (string) $salesInvoice->partner_id;
            $this->warehouseId = $salesInvoice->warehouse_id === null ? '' : (string) $salesInvoice->warehouse_id;
            $this->invoiceDate = $salesInvoice->invoice_date->toDateString();
            $this->dueDate = $salesInvoice->due_date->toDateString();
            $this->notes = (string) $salesInvoice->notes;
            $this->lines = $salesInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'vat_rate' => (string) $line->vat_rate,
            ])->toArray();
        } else {
            $this->invoiceDate = now()->toDateString();
            $this->dueDate = now()->toDateString();
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'item_id' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'vat_rate' => $this->company->is_vat_registered ? '18.00' : '0.00',
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function selectItem(int $index, string $itemId): void
    {
        $this->lines[$index]['item_id'] = $itemId;

        if ($itemId === '') {
            return;
        }

        $item = Item::where('company_id', $this->company->id)->find($itemId);

        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';
        }
    }

    public function save(): void
    {
        Gate::authorize($this->salesInvoice ? 'update' : 'create', $this->salesInvoice ?? SalesInvoice::class);

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
        ]);

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') === '' && trim((string) ($line['description'] ?? '')) === '') {
                $this->addError("lines.{$index}.description", 'Each line needs an item or a description.');

                return;
            }
        }

        $hasItemLines = collect($this->lines)->contains(fn ($line) => ($line['item_id'] ?? '') !== '');

        if ($hasItemLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'A warehouse is required when any line references an item.');

            return;
        }

        DB::transaction(function () {
            $invoice = $this->salesInvoice ?? new SalesInvoice([
                'company_id' => $this->company->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $invoice->company_id = $this->company->id;
            $invoice->partner_id = $this->partnerId;
            $invoice->warehouse_id = $this->warehouseId ?: null;
            $invoice->invoice_date = $this->invoiceDate;
            $invoice->due_date = $this->dueDate;
            $invoice->notes = $this->notes ?: null;

            if (! $invoice->exists) {
                $invoice->status = 'draft';
                $invoice->created_by = auth()->id();
            }

            $invoice->save();
            $invoice->lines()->delete();

            foreach ($this->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?: null,
                    'description' => $line['description'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                ]);
            }

            $this->salesInvoice = $invoice;
        });

        $this->redirect(route('sales-invoices.show', [$this->company, $this->salesInvoice]));
    }

    public function render()
    {
        return view('livewire.invoicing.sales-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
