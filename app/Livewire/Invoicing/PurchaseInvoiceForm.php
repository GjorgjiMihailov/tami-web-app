<?php

namespace App\Livewire\Invoicing;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Support\VatMath;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class PurchaseInvoiceForm extends Component
{
    use WithFileUploads;

    private const MAX_IMAGE_KILOBYTES = 6144;

    public $scanFile = null;

    public bool $scanRead = false;

    public array $scanWarnings = [];

    /** @var array{name: string, tax_id: string}|null */
    public ?array $suggestedPartner = null;

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

    public function updatedScanFile(): void
    {
        $this->scanRead = false;
        $this->scanWarnings = [];
        $this->suggestedPartner = null;
    }

    /** Читањето чини пари, па е само за канцеларијата и само кога има клуч. */
    public function canReadScans(): bool
    {
        return filled(config('services.anthropic.key'))
            && auth()->user()?->hasAnyRole(['admin', 'accountant']);
    }

    public function readScan(): void
    {
        abort_unless($this->canReadScans(), 403);

        $this->resetErrorBag('scanFile');
        $this->scanWarnings = [];
        $this->suggestedPartner = null;

        $this->validate([
            'scanFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($this->scanFile->getMimeType() !== 'application/pdf'
            && $this->scanFile->getSize() > self::MAX_IMAGE_KILOBYTES * 1024) {
            $this->addError('scanFile', 'Сликата е преголема — прати помала слика (до 6 МБ) или PDF.');

            return;
        }

        try {
            $scanned = app(ScannedInvoiceReader::class)->read($this->scanFile, $this->company);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('scanFile', 'Не можев да ја прочитам фактурата — внеси ја рачно.');

            return;
        }

        $this->applyScan($scanned);
        $this->scanRead = true;
    }

    public function createSuggestedPartner(): void
    {
        abort_unless($this->canReadScans(), 403);

        if ($this->suggestedPartner === null) {
            return;
        }

        $this->validate([
            'suggestedPartner.name' => 'required|string|max:255',
            'suggestedPartner.tax_id' => 'required|string|max:255',
        ]);

        $partner = Partner::create([
            'company_id' => $this->company->id,
            'name' => $this->suggestedPartner['name'],
            'tax_id' => $this->suggestedPartner['tax_id'],
        ]);

        $this->partnerId = (string) $partner->id;
        $this->suggestedPartner = null;
    }

    private function applyScan(ScannedInvoice $scanned): void
    {
        if ($scanned->invoiceCount === 0) {
            $this->scanWarnings[] = 'Документот не изгледа како фактура — провери дали е качен вистинскиот фајл.';
        } elseif ($scanned->invoiceCount !== null && $scanned->invoiceCount > 1) {
            $this->scanWarnings[] = "Фајлот содржи {$scanned->invoiceCount} фактури, а прочитана е само првата. "
                .'Качи ја секоја фактура како посебен фајл.';
        }

        // Правилото од излезната, наопаку: тука фирмата мора да е КУПУВАЧ.
        // ЕДБ на купувачот еднакво на нашето е доказ; инаку одговорот за улогата.
        $ours = $this->digits((string) $this->company->tax_id);
        $buyerDigits = $this->digits((string) $scanned->buyerTaxId);
        $weAreBuyer = ($ours !== '' && $buyerDigits === $ours) || $scanned->ourCompanyRole === 'buyer';

        if (! $weAreBuyer) {
            if ($scanned->ourCompanyRole === 'seller') {
                $this->scanWarnings[] = 'Оваа фирма е ПРОДАВАЧ на фактурата — ова е излезна, не влезна фактура. Провери го фајлот.';
            } elseif ($scanned->ourCompanyRole === 'absent') {
                $this->scanWarnings[] = 'Оваа фирма не се спомнува на документот — провери дали е качен вистинскиот фајл.';
            } else {
                $this->scanWarnings[] = 'Не можев да потврдам дека оваа фирма е купувач на фактурата — провери го фајлот.';
            }
        }

        if (filled($scanned->invoiceNumber)) {
            $this->supplierInvoiceNumber = mb_substr($scanned->invoiceNumber, 0, 255);
        }

        if (filled($scanned->invoiceDate)) {
            $this->invoiceDate = $scanned->invoiceDate;
        }

        if (filled($scanned->dueDate)) {
            $this->dueDate = $scanned->dueDate;
        }

        // Добавувач по ЕДБ — точно совпаѓање на цифрите, без погодување по име.
        $sellerDigits = $this->digits((string) $scanned->sellerTaxId);

        if ($sellerDigits !== '' && $sellerDigits !== $ours) {
            $partner = Partner::where('company_id', $this->company->id)
                ->whereNotNull('tax_id')
                ->get()
                ->first(fn (Partner $candidate) => $this->digits((string) $candidate->tax_id) === $sellerDigits);

            if ($partner) {
                $this->partnerId = (string) $partner->id;
            } elseif (filled($scanned->sellerName)) {
                $this->suggestedPartner = [
                    'name' => (string) $scanned->sellerName,
                    'tax_id' => (string) $scanned->sellerTaxId,
                ];
            }
        }

        if ($scanned->lines === []) {
            return;
        }

        $items = Item::where('company_id', $this->company->id)->where('is_active', true)->get();

        $this->lines = array_map(function (ScannedInvoiceLine $line) use ($items) {
            $description = (string) $line->description;
            $rate = filled($line->vatRate) ? (string) $line->vatRate : '0.00';
            $price = filled($line->unitPrice) ? (string) $line->unitPrice : '0';

            // Артикл се врзува само по точно име (без разлика на големи/мали
            // букви); инаку ставката е слободен текст, а човекот избира: врзи
            // со постоечки или „Внеси како артикл".
            $match = $description === '' ? null : $items->first(
                fn (Item $item) => mb_strtolower(trim($item->name)) === mb_strtolower(trim($description))
            );

            return [
                'item_id' => $match ? (string) $match->id : '',
                'account_id' => '',
                'description' => $description,
                'quantity' => filled($line->quantity) ? $line->quantity : '1',
                'unit_price' => $price,
                'unit_price_gross' => VatMath::grossFromNet($price, $rate),
                'price_basis' => 'net',
                'vat_rate' => $rate,
                'vat_deductible' => true,
                'needs_review' => false,
            ];
        }, $scanned->lines);
    }

    /**
     * Ставка што ја нема во Артикли се внесува како артикл од залиха токму
     * тука, без да се напушта фактурата. Приемот на залиха потоа го прави
     * потврдата на фактурата, како и за секој друг артикл.
     */
    public function addLineAsItem(int $index): void
    {
        Gate::authorize('create', Item::class);

        $line = $this->lines[$index] ?? null;

        if ($line === null || ($line['item_id'] ?? '') !== '') {
            return;
        }

        $name = trim((string) ($line['description'] ?? ''));

        if ($name === '') {
            $this->addError("lines.{$index}.description", 'Впиши опис — тој станува името на артиклот.');

            return;
        }

        $item = Item::where('company_id', $this->company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $item) {
            $next = Item::where('company_id', $this->company->id)->count() + 1;

            do {
                $code = 'A-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
            } while (Item::where('company_id', $this->company->id)->where('code', $code)->exists());

            $item = Item::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => mb_substr($name, 0, 255),
                'unit_of_measure' => 'piece',
                'vat_rate' => is_numeric($line['vat_rate'] ?? null) ? $line['vat_rate'] : '18.00',
                'type' => 'product',
                'is_active' => true,
            ]);
        }

        $this->lines[$index]['item_id'] = (string) $item->id;
        $this->lines[$index]['account_id'] = '';
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
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
