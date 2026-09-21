<?php

namespace App\Livewire\Invoicing;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use App\Support\VatMath;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceForm extends Component
{
    public Company $company;

    public ?PurchaseInvoice $purchaseInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $supplierInvoiceNumber = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    public array $lines = [];

    public int $workingYear = 0;

    public function mount(Company $company, ?PurchaseInvoice $purchaseInvoice = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);

        Gate::authorize($purchaseInvoice ? 'update' : 'create', $purchaseInvoice ?? PurchaseInvoice::class);

        if ($purchaseInvoice) {
            if ($purchaseInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($purchaseInvoice->status !== 'draft') {
                abort(403, 'Можат да се менуваат само нацрт влезни фактури.');
            }
        }

        $this->purchaseInvoice = $purchaseInvoice;

        if ($purchaseInvoice) {
            $this->partnerId = (string) $purchaseInvoice->partner_id;
            $this->warehouseId = $purchaseInvoice->warehouse_id === null ? '' : (string) $purchaseInvoice->warehouse_id;
            $this->supplierInvoiceNumber = $purchaseInvoice->supplier_invoice_number;
            $this->invoiceDate = $purchaseInvoice->invoice_date->toDateString();
            $this->dueDate = $purchaseInvoice->due_date->toDateString();
            $this->notes = (string) $purchaseInvoice->notes;
            $this->lines = $purchaseInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'account_id' => $line->account_id === null ? '' : (string) $line->account_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                // Кај бруто ставка нето цената се ИЗВЕДУВА од бруто цената и
                // стапката, не се чита од базата: зачуваната може да е
                // остаток од поранешна стапка, а формата мора да е согласна
                // сама со себе штом ќе се отвори.
                'unit_price' => $line->isGrossEntered()
                    ? VatMath::netFromGross((string) $line->unit_price_gross, (string) $line->vat_rate)
                    : (string) $line->unit_price,
                'unit_price_gross' => $line->isGrossEntered()
                    ? (string) $line->unit_price_gross
                    : VatMath::grossFromNet((string) $line->unit_price, (string) $line->vat_rate),
                'price_basis' => $line->isGrossEntered() ? 'gross' : 'net',
                'vat_rate' => (string) $line->vat_rate,
                'vat_deductible' => $line->vat_deductible,
                'needs_review' => $line->needs_review,
            ])->toArray();
        } else {
            $this->invoiceDate = WorkingYear::defaultDate($this->workingYear);
            $this->dueDate = WorkingYear::defaultDate($this->workingYear);
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        $rate = $this->company->is_vat_registered ? '18.00' : '0.00';

        return [
            'item_id' => '',
            'account_id' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'unit_price_gross' => VatMath::grossFromNet('0', $rate),
            'price_basis' => 'net',
            'vat_rate' => $rate,
            'vat_deductible' => true,
            'needs_review' => false,
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

        if (! $item) {
            return;
        }

        // A service keeps the expense account the user picked — it books there,
        // not to inventory. Only a stocked product makes the account irrelevant.
        if (! $item->isService()) {
            $this->lines[$index]['account_id'] = '';
        }

        $this->lines[$index]['description'] = $item->name;
        $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';
        $this->lines[$index]['price_basis'] = 'net';
        $this->refreshPrices($index);
    }

    /**
     * Нето и бруто цената се држат една со друга, но полето во кое човекот
     * пишува НИКОГАШ не се преправа под неговите прсти. Она што го впишал е
     * основата на сметката; другото поле е изведеното.
     */
    public function updated(string $name): void
    {
        if (! preg_match('/^lines\.(\d+)\.(unit_price|unit_price_gross|vat_rate)$/', $name, $matches)) {
            return;
        }

        $index = (int) $matches[1];

        if (! isset($this->lines[$index])) {
            return;
        }

        if ($matches[2] !== 'vat_rate') {
            $this->lines[$index]['price_basis'] = $matches[2] === 'unit_price_gross' ? 'gross' : 'net';
        }

        $this->refreshPrices($index);
    }

    /**
     * Го пресметува полето што НЕ е основата. Кога основата е бруто, се менува
     * нето цената; кога е нето, се менува бруто цената. Промена на стапката ја
     * задржува основата и го пресметува другото поле одново.
     */
    private function refreshPrices(int $index): void
    {
        $line = $this->lines[$index];
        $rate = (string) ($line['vat_rate'] ?? '0');

        if (($line['price_basis'] ?? 'net') === 'gross') {
            $this->lines[$index]['unit_price'] = VatMath::netFromGross((string) ($line['unit_price_gross'] ?? '0'), $rate);

            return;
        }

        $this->lines[$index]['unit_price_gross'] = VatMath::grossFromNet((string) ($line['unit_price'] ?? '0'), $rate);
    }

    /**
     * Истата пресметка што ќе ја направи и `PurchaseInvoiceLine` по зачувување
     * — екранот и книжењето мора да покажат иста бројка.
     *
     * @param  array<string, mixed>  $line
     * @return array{net: string, vat: string, gross: string}
     */
    private function lineAmounts(array $line, bool $vatRegistered): array
    {
        $quantity = (string) ($line['quantity'] ?? '0');
        $rate = $vatRegistered ? (string) ($line['vat_rate'] ?? '0') : '0';

        return ($line['price_basis'] ?? 'net') === 'gross'
            ? VatMath::lineFromGross($quantity, (string) ($line['unit_price_gross'] ?? '0'), $rate)
            : VatMath::lineFromNet($quantity, (string) ($line['unit_price'] ?? '0'), $rate);
    }

    /**
     * @return array<int, string> item id => 'product'|'service'
     */
    private function itemTypes(): array
    {
        $ids = collect($this->lines)
            ->pluck('item_id')
            ->filter(fn ($id) => $id !== '' && $id !== null)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Item::where('company_id', $this->company->id)
            ->whereIn('id', $ids)
            ->pluck('type', 'id')
            ->all();
    }

    /**
     * @param  array<int, string>  $types
     */
    private function isStockLine(array $line, array $types): bool
    {
        $id = $line['item_id'] ?? '';

        return $id !== '' && ($types[(int) $id] ?? 'product') === 'product';
    }

    public function save(): void
    {
        Gate::authorize($this->purchaseInvoice ? 'update' : 'create', $this->purchaseInvoice ?? PurchaseInvoice::class);

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'supplierInvoiceNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('purchase_invoices', 'supplier_invoice_number')
                    ->where('company_id', $this->company->id)
                    ->where('partner_id', $this->partnerId)
                    ->ignore($this->purchaseInvoice?->id),
            ],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.account_id' => ['nullable', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            // Изведени, не внесени: ставка што доаѓа од скен или од друг код
            // не мора да ги носи, а тогаш важи стариот начин на сметање.
            'lines.*.unit_price_gross' => 'nullable|numeric|min:0',
            'lines.*.price_basis' => 'nullable|in:net,gross',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
        ]);

        $types = $this->itemTypes();

        foreach ($this->lines as $index => $line) {
            if (! $this->isStockLine($line, $types) && ($line['account_id'] ?? '') === '') {
                $this->addError(
                    "lines.{$index}.account_id",
                    'Секоја ставка што не е артикл од залиха мора да содржи сметка за трошок.'
                );

                return;
            }
        }

        $hasStockLines = collect($this->lines)->contains(fn ($line) => $this->isStockLine($line, $types));

        if ($hasStockLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'Потребен е магацин кога некоја ставка содржи артикл од залиха.');

            return;
        }

        DB::transaction(function () use ($types) {
            $invoice = $this->purchaseInvoice ?? new PurchaseInvoice([
                'company_id' => $this->company->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $invoice->company_id = $this->company->id;
            $invoice->partner_id = $this->partnerId;
            $invoice->warehouse_id = $this->warehouseId ?: null;
            $invoice->supplier_invoice_number = $this->supplierInvoiceNumber;
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
                    'account_id' => $this->isStockLine($line, $types) ? null : ($line['account_id'] ?: null),
                    'description' => $line['description'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    // Пополнето само кога човекот навистина впишал бруто цена.
                    // Тогаш ставката се смета наназад од неа; инаку останува
                    // стариот начин и ништо во пресметката не се менува.
                    'unit_price_gross' => ($line['price_basis'] ?? 'net') === 'gross' ? $line['unit_price_gross'] : null,
                    'vat_rate' => $line['vat_rate'],
                    'vat_deductible' => $line['vat_deductible'] ?? true,
                    'needs_review' => $line['needs_review'] ?? false,
                ]);
            }

            $this->purchaseInvoice = $invoice;
        });

        $this->redirect(route('purchase-invoices.show', [$this->company, $this->purchaseInvoice]));
    }

    public function render()
    {
        $vatRegistered = (bool) $this->company->is_vat_registered;
        $types = $this->itemTypes();

        $rows = [];
        $net = '0.00';
        $vat = '0.00';
        $nonDeductibleVat = '0.00';

        foreach ($this->lines as $index => $line) {
            $amounts = $this->lineAmounts($line, $vatRegistered);
            $deductible = $vatRegistered && ($line['vat_deductible'] ?? true);

            $rows[$index] = $amounts + ['is_stock' => $this->isStockLine($line, $types)];

            $net = bcadd($net, $amounts['net'], 2);
            $vat = bcadd($vat, $amounts['vat'], 2);

            if (! $deductible) {
                $nonDeductibleVat = bcadd($nonDeductibleVat, $amounts['vat'], 2);
            }
        }

        return view('livewire.invoicing.purchase-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('type')->orderBy('name')->get(),
            'accounts' => Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get(),
            'rows' => $rows,
            'vatRegistered' => $vatRegistered,
            'requiresWarehouse' => collect($rows)->contains(fn ($row) => $row['is_stock']),
            'totals' => [
                'net' => $net,
                'vat' => $vat,
                'gross' => bcadd($net, $vat, 2),
                'non_deductible_vat' => $nonDeductibleVat,
            ],
        ]);
    }
}
