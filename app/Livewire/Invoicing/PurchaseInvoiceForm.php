<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use App\Services\ExchangeRateService;
use App\Services\Inventory\CustomsTariffAggregator;
use App\Services\Inventory\LandedCostAllocator;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\ImportScanChecks;
use App\Services\Invoicing\ImportScanMapper;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Services\PartnerInsights;
use App\Support\Bcmath;
use App\Support\VatMath;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
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

    public $ecdFile = null;

    public $importInvoiceFile = null;

    public $forwarderFile = null;

    /** @var string[] */
    public array $importScanWarnings = [];

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

    /** Број на нарачка (кај нас или кај добавувачот). */
    public string $orderNumber = '';

    /** Помошно поле: рокот на плаќање ја пресметува датата на доспевање. Не се чува. */
    public string $paymentTermsDays = '';

    public array $lines = [];

    public bool $isImport = false;

    public string $customsDeclarationNumber = '';

    public string $importDate = '';

    public string $importCurrencyCode = 'EUR';

    public string $importExchangeRate = '';

    public array $importCosts = [];

    public array $tariffLines = [];

    public string $importTab = 'costs';

    public bool $showLandedPreview = false;

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
            $this->orderNumber = (string) $purchaseInvoice->order_number;
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
                'discount_percent' => (string) $line->discount_percent,
                'vat_deductible' => $line->vat_deductible,
                'needs_review' => $line->needs_review,
            ])->toArray();
            $this->isImport = (bool) $purchaseInvoice->is_import;
            $this->customsDeclarationNumber = (string) $purchaseInvoice->customs_declaration_number;
            $this->importDate = $purchaseInvoice->import_date?->toDateString() ?? '';
            $this->importCurrencyCode = $purchaseInvoice->import_currency_code ?? 'EUR';
            $this->importExchangeRate = $purchaseInvoice->import_exchange_rate === null ? '' : (string) $purchaseInvoice->import_exchange_rate;
            $this->importCosts = $purchaseInvoice->importCosts->map(fn ($cost) => [
                'payee_name' => $cost->payee_name,
                'reference_number' => (string) $cost->reference_number,
                'foreign_amount' => $cost->foreign_amount === null ? '' : (string) $cost->foreign_amount,
                'base_amount' => (string) $cost->base_amount,
                'vat_amount' => (string) $cost->vat_amount,
            ])->toArray();
            $this->tariffLines = $purchaseInvoice->tariffLines->map(fn ($tariff) => [
                'tariff_code' => $tariff->tariff_code,
                'foreign_amount' => $tariff->foreign_amount === null ? '' : (string) $tariff->foreign_amount,
                'customs_duty' => (string) $tariff->customs_duty,
                'vat_amount' => (string) $tariff->vat_amount,
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
            'discount_percent' => '0',
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

    public function addImportCost(): void
    {
        $this->importCosts[] = ['payee_name' => '', 'reference_number' => '', 'foreign_amount' => '', 'base_amount' => '0', 'vat_amount' => '0'];
    }

    public function removeImportCost(int $index): void
    {
        unset($this->importCosts[$index]);
        $this->importCosts = array_values($this->importCosts);
    }

    public function addTariffLine(): void
    {
        $this->tariffLines[] = ['tariff_code' => '', 'foreign_amount' => '', 'customs_duty' => '0', 'vat_amount' => '0'];
    }

    public function removeTariffLine(int $index): void
    {
        unset($this->tariffLines[$index]);
        $this->tariffLines = array_values($this->tariffLines);
    }

    public function fetchImportRate(): void
    {
        $this->resetErrorBag('importExchangeRate');

        if ($this->importCurrencyCode === '' || $this->importDate === '') {
            $this->addError('importExchangeRate', 'Внеси датум на увоз и валута пред да го повлечеш курсот.');

            return;
        }

        try {
            $rate = app(ExchangeRateService::class)->getRate($this->importCurrencyCode, Carbon::parse($this->importDate));
        } catch (\Throwable) {
            $this->addError('importExchangeRate', 'Не можев да го повлечам курсот — внеси го рачно.');

            return;
        }

        $this->importExchangeRate = (string) $rate;
    }

    public function revealLandedPreview(): void
    {
        $this->showLandedPreview = true;
    }

    public function updatedIsImport(bool $value): void
    {
        if (! $value) {
            $this->showLandedPreview = false;
        }
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
        $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? $item->purchaseVatRate() : '0.00';

        if ($item->cost_price !== null) {
            $this->lines[$index]['unit_price'] = (string) $item->cost_price;
        }

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

        $discount = (string) ($line['discount_percent'] ?? '0');

        return ($line['price_basis'] ?? 'net') === 'gross'
            ? VatMath::lineFromGross($quantity, (string) ($line['unit_price_gross'] ?? '0'), $rate, $discount)
            : VatMath::lineFromNet($quantity, (string) ($line['unit_price'] ?? '0'), $rate, $discount);
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

    /**
     * Чита до три увозни документи по фиксен редослед: ЕЦД (од него е
     * курсот), па фактурата од добавувач (се претвора по тој курс), па
     * шпедитерската. Секое ново читање ги заменува редовите од истиот
     * извор, не ги удвојува. Грешка на еден документ не ги крши другите.
     */
    public function readImportDocuments(): void
    {
        abort_unless($this->canReadScans(), 403);

        // Три повици кон API на еден барање; ограничувањето на PHP не е
        // проверено на серверот, па се бара повеќе време наместо да се верува
        // на стандардните 30 секунди. Само кога веќе има ограничување: во CLI
        // (тестови, artisan) е 0 = без граница, а `set_time_limit` важи за
        // цел процес — па повикот од еден тест би ја прекинал целата серија
        // по 240 секунди.
        $limit = (int) ini_get('max_execution_time');

        if ($limit !== 0 && $limit < 240) {
            @set_time_limit(240);
        }

        $this->resetErrorBag(['ecdFile', 'importInvoiceFile', 'forwarderFile', 'importDocuments']);
        $this->importScanWarnings = [];

        $rules = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
        $this->validate(['ecdFile' => $rules, 'importInvoiceFile' => $rules, 'forwarderFile' => $rules]);

        if ($this->ecdFile === null && $this->importInvoiceFile === null && $this->forwarderFile === null) {
            $this->addError('importDocuments', 'Прикачи барем еден документ.');

            return;
        }

        // Истото правило како кај обичниот скен: преголема слика се одбива
        // пред да се плати повик, на полето на тој документ.
        $oversized = [];

        foreach (['ecdFile', 'importInvoiceFile', 'forwarderFile'] as $slot) {
            $file = $this->{$slot};

            if ($file !== null && $file->getMimeType() !== 'application/pdf'
                && $file->getSize() > self::MAX_IMAGE_KILOBYTES * 1024) {
                $this->addError($slot, 'Сликата е преголема — прати помала слика (до 6 МБ) или PDF.');
                $oversized[$slot] = true;
            }
        }

        $mapper = new ImportScanMapper;
        $warnings = [];
        $ecd = null;
        $invoice = null;
        $forwarder = null;
        $rateFromNbrm = false;
        $invoiceNeedsRate = false;

        if ($this->ecdFile !== null && ! isset($oversized['ecdFile'])) {
            try {
                $ecd = app(CustomsDeclarationReader::class)->read($this->ecdFile, $this->company);
            } catch (\Throwable $e) {
                report($e);
                $this->addError('ecdFile', 'Не можев да ја прочитам ЕЦД — внеси ја рачно.');
            }
        }

        if ($this->importInvoiceFile !== null && ! isset($oversized['importInvoiceFile'])) {
            try {
                $invoice = app(ScannedInvoiceReader::class)->read($this->importInvoiceFile, $this->company);
            } catch (\Throwable $e) {
                report($e);
                $this->addError('importInvoiceFile', 'Не можев да ја прочитам фактурата — внеси ја рачно.');
            }
        }

        if ($this->forwarderFile !== null && ! isset($oversized['forwarderFile'])) {
            try {
                $forwarder = app(ScannedInvoiceReader::class)->read($this->forwarderFile, $this->company);
            } catch (\Throwable $e) {
                report($e);
                $this->addError('forwarderFile', 'Не можев да ја прочитам шпедитерската фактура — внеси ја рачно.');
            }
        }

        if ($ecd === null && $invoice === null && $forwarder === null) {
            return;
        }

        $this->isImport = true;

        if ($ecd !== null) {
            $fields = $mapper->declarationFields($ecd);

            $this->customsDeclarationNumber = $fields['customsDeclarationNumber'];
            $this->importDate = $fields['importDate'];
            $this->importCurrencyCode = $fields['importCurrencyCode'] ?? $this->importCurrencyCode;
            $this->importExchangeRate = $fields['importExchangeRate'];

            if ($fields['importCurrencyCode'] === null && filled($ecd->currency)) {
                $warnings[] = "Валутата на ЕЦД ({$ecd->currency}) не е меѓу понудените — избери ја рачно.";
            }

            $summary = (new CustomsTariffAggregator)->aggregate($ecd->items);

            $this->tariffLines = array_map(fn (array $row) => $row + ['source' => 'ecd'], $summary['rows']);
        }

        if ($invoice !== null) {
            $rate = Bcmath::isPlainNumber($this->importExchangeRate) ? $this->importExchangeRate : null;
            $currency = filled($invoice->currency) ? $invoice->currency : null;

            // Валутата на фактурата не е прочитана: ако ЕЦД од истото читање има
            // странска валута, фактурата се смета во неа (по курсот на ЕЦД);
            // инаку износите се применуваат како што се, со предупредување.
            if ($currency === null) {
                if ($ecd !== null && filled($ecd->currency) && $ecd->currency !== 'MKD') {
                    $currency = $ecd->currency;
                } else {
                    $warnings[] = 'Валутата на фактурата не е прочитана — износите се третирани како денари, провери ги.';
                }
            }

            if ($currency !== null && $currency !== 'MKD') {
                if ($rate === null) {
                    $date = filled($invoice->invoiceDate) ? $invoice->invoiceDate : $this->invoiceDate;

                    try {
                        $rate = (string) app(ExchangeRateService::class)->getRate($currency, Carbon::parse($date));
                        $this->importExchangeRate = $rate;
                        $this->importCurrencyCode = in_array($currency, ImportScanMapper::CURRENCIES, true) ? $currency : $this->importCurrencyCode;
                        $rateFromNbrm = true;
                    } catch (\Throwable $e) {
                        report($e);
                        $this->addError('importExchangeRate', 'Нема ЕЦД и не можев да го повлечам курсот од НБРМ — внеси го курсот рачно и притисни „Прочитај ги документите“ повторно.');
                        $rate = null;
                        $invoiceNeedsRate = true;
                    }
                }

                if ($rate !== null) {
                    $converted = $mapper->convertInvoice($invoice, $rate);
                    $warnings = array_merge($warnings, $converted['warnings']);

                    $this->resetScanFeedback();
                    $this->applyScan($converted['invoice'], chargesOnTransportAccount: true);
                    $this->scanRead = true;

                    $this->replaceImportCosts($converted['costs'], 'invoice');
                }
            } else {
                $this->resetScanFeedback();
                $this->applyScan($invoice);
                $this->scanRead = true;

                // Денарска фактура нема ред „транспорт" од фактурата: се отстрануваат
                // и редовите од претходно читање во странска валута.
                $this->replaceImportCosts([], 'invoice');
            }
        }

        if ($forwarder !== null) {
            $cost = $mapper->forwarderCost($forwarder);
            $warnings = array_merge($warnings, $cost['warnings']);

            $this->replaceImportCosts([$cost['row']], 'forwarder');
        }

        $warnings = array_merge($warnings, (new ImportScanChecks)->run(
            $ecd, $invoice, $forwarder, (string) $this->company->tax_id, $rateFromNbrm,
        ));

        $this->importScanWarnings = $warnings;
        $this->ecdFile = $this->forwarderFile = null;

        // Без курс фактурата не е применета: прикачувањето останува за да
        // може да се внесе курсот и да се прочита пак. Второто читање повторно
        // го повикува (платениот) API за фактурата — ништо не се кешира.
        if (! $invoiceNeedsRate) {
            $this->importInvoiceFile = null;
        }
    }

    private function resetScanFeedback(): void
    {
        $this->scanWarnings = [];
        $this->suggestedPartner = null;
    }

    /**
     * Додава редови на увозни трошоци од скен. Претходно ги отстранува
     * постојните редови од истиот извор И редовите со ист добавувач и број на
     * документ — запишаните нацрти го губат `source`, па без второто повторно
     * читање по отворање на нацрт ги удвојува трошоците.
     *
     * @param  array<int, array<string, mixed>>  $newRows
     */
    private function replaceImportCosts(array $newRows, string $source): void
    {
        $norm = fn ($value) => mb_strtolower(trim((string) $value));
        $identities = [];

        foreach ($newRows as $row) {
            $payee = $norm($row['payee_name'] ?? '');
            $reference = $norm($row['reference_number'] ?? '');

            if ($payee !== '' || $reference !== '') {
                $identities[] = [$payee, $reference];
            }
        }

        $this->importCosts = array_values(array_filter($this->importCosts, function ($existing) use ($source, $norm, $identities) {
            if (($existing['source'] ?? '') === $source) {
                return false;
            }

            $key = [$norm($existing['payee_name'] ?? ''), $norm($existing['reference_number'] ?? '')];

            return ! in_array($key, $identities, true);
        }));

        $this->importCosts = array_merge($this->importCosts, $newRows);
    }

    /**
     * Еден клик за сите ставки без артикл (фактура од ~110 ставки): исто
     * правило како „Внеси како артикл" — исто име (без разлика на големи/мали
     * букви) го користи постоечкиот артикл — но со еден бројач на шифри.
     */
    public function addAllUnknownLinesAsItems(): void
    {
        Gate::authorize('create', Item::class);

        $existing = Item::where('company_id', $this->company->id)->get(['id', 'code', 'name']);
        $byName = $existing->keyBy(fn ($item) => mb_strtolower($item->name));
        $codes = $existing->pluck('code')->flip();
        $next = $existing->count() + 1;

        foreach ($this->lines as $index => $line) {
            // Ставка со сметка (на пр. транспортот на 660) не е стока.
            if (($line['item_id'] ?? '') !== '' || ($line['account_id'] ?? '') !== '') {
                continue;
            }

            $name = trim((string) ($line['description'] ?? ''));

            if ($name === '') {
                continue;
            }

            $item = $byName->get(mb_strtolower($name));

            if (! $item) {
                do {
                    $code = 'A-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
                } while ($codes->has($code));

                $item = Item::create([
                    'company_id' => $this->company->id,
                    'code' => $code,
                    'name' => mb_substr($name, 0, 255),
                    'unit_of_measure' => 'бр.',
                    'vat_rate' => $this->newItemVatRate($line),
                    'type' => 'product',
                    'is_active' => true,
                ]);

                $byName->put(mb_strtolower($name), $item);
                $codes->put($code, true);
            }

            $this->lines[$index]['item_id'] = (string) $item->id;
            $this->lines[$index]['account_id'] = '';
        }
    }

    /**
     * ДДВ стапка на нов артикл од ставка. Кај увоз ставките се претворени со
     * ДДВ 0 (увозното ДДВ е во ЕЦД), па тоа не смее да стане стапка на
     * артиклот при продажба — важи стандардната на фирмата.
     *
     * @param  array<string, mixed>  $line
     */
    private function newItemVatRate(array $line): string
    {
        if ($this->isImport) {
            return $this->company->is_vat_registered ? '18.00' : '0.00';
        }

        return Bcmath::isPlainNumber($line['vat_rate'] ?? null) ? (string) $line['vat_rate'] : '18.00';
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

    private function applyScan(ScannedInvoice $scanned, bool $chargesOnTransportAccount = false): void
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

        // Увоз: ставката „транспорт/осигурување" останува на фактурата (долгот
        // кон добавувачот = хартијата) на сметка 660; истиот износ е и во
        // „Увозни трошоци" за магацинската вредност. Кај обичен скен — без сметка.
        $chargeAccountId = '';

        if ($chargesOnTransportAccount && $this->isImport
            && collect($scanned->lines)->contains(fn (ScannedInvoiceLine $l) => $l->kind === 'charge')) {
            $chargeAccountId = (string) (Account::where('company_id', $this->company->id)->where('code', '660')->value('id') ?? '');
        }

        $this->lines = array_map(function (ScannedInvoiceLine $line) use ($items, $chargeAccountId) {
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
                'account_id' => $line->kind === 'charge' ? $chargeAccountId : '',
                'description' => $description,
                'quantity' => filled($line->quantity) ? $line->quantity : '1',
                'unit_price' => $price,
                'unit_price_gross' => VatMath::grossFromNet($price, $rate),
                'price_basis' => 'net',
                'vat_rate' => $rate,
                'discount_percent' => '0',
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
                'unit_of_measure' => 'бр.',
                'vat_rate' => $this->newItemVatRate($line),
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

    /**
     * Зачувува влезната фактура како нацрт. Враќа дали успеало — грешките се веќе на екранот.
     */
    private function persist(): bool
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
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'orderNumber' => 'nullable|string|max:100',
            'isImport' => 'boolean',
            'customsDeclarationNumber' => 'nullable|string|max:255',
            'importDate' => 'nullable|date',
            'importCurrencyCode' => 'nullable|string|size:3',
            'importExchangeRate' => 'nullable|numeric|min:0',
            'importCosts.*.payee_name' => 'nullable|string|max:255',
            'importCosts.*.reference_number' => 'nullable|string|max:255',
            'importCosts.*.foreign_amount' => 'nullable|numeric|min:0',
            'importCosts.*.base_amount' => 'nullable|numeric|min:0',
            'importCosts.*.vat_amount' => 'nullable|numeric|min:0',
            'tariffLines.*.tariff_code' => 'nullable|string|max:255',
            'tariffLines.*.foreign_amount' => 'nullable|numeric|min:0',
            'tariffLines.*.customs_duty' => 'nullable|numeric|min:0',
            'tariffLines.*.vat_amount' => 'nullable|numeric|min:0',
        ]);

        $types = $this->itemTypes();

        foreach ($this->lines as $index => $line) {
            if (! $this->isStockLine($line, $types) && ($line['account_id'] ?? '') === '') {
                $this->addError(
                    "lines.{$index}.account_id",
                    'Секоја ставка што не е артикл од залиха мора да содржи сметка за трошок.'
                );

                return false;
            }
        }

        $hasStockLines = collect($this->lines)->contains(fn ($line) => $this->isStockLine($line, $types));

        if ($hasStockLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'Потребен е магацин кога некоја ставка содржи артикл од залиха.');

            return false;
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
            $invoice->order_number = $this->orderNumber ?: null;
            $invoice->is_import = $this->isImport;
            // Сите четири увозни полиња одат заедно — откако ќе се одштиклира
            // „Фактура од увоз", ниедно не смее да остане старо во базата.
            $invoice->customs_declaration_number = $this->isImport ? ($this->customsDeclarationNumber ?: null) : null;
            $invoice->import_date = $this->isImport ? ($this->importDate ?: null) : null;
            $invoice->import_currency_code = $this->isImport ? ($this->importCurrencyCode ?: null) : null;
            $invoice->import_exchange_rate = $this->isImport && $this->importExchangeRate !== '' ? $this->importExchangeRate : null;

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
                    'discount_percent' => filled($line['discount_percent'] ?? null) ? $line['discount_percent'] : 0,
                    'vat_deductible' => $line['vat_deductible'] ?? true,
                    'needs_review' => $line['needs_review'] ?? false,
                ]);
            }

            $invoice->importCosts()->delete();
            $invoice->tariffLines()->delete();

            if ($this->isImport) {
                foreach ($this->importCosts as $order => $cost) {
                    $isBlankRow = trim((string) ($cost['payee_name'] ?? '')) === ''
                        && trim((string) ($cost['reference_number'] ?? '')) === ''
                        && trim((string) ($cost['foreign_amount'] ?? '')) === ''
                        && (float) ($cost['base_amount'] ?? 0) === 0.0
                        && (float) ($cost['vat_amount'] ?? 0) === 0.0;

                    if ($isBlankRow) {
                        continue;
                    }

                    $invoice->importCosts()->create([
                        'payee_name' => $cost['payee_name'] ?: '—',
                        'reference_number' => $cost['reference_number'] ?: null,
                        'foreign_amount' => filled($cost['foreign_amount'] ?? null) ? $cost['foreign_amount'] : null,
                        'base_amount' => $cost['base_amount'] ?: '0',
                        'vat_amount' => $cost['vat_amount'] ?: '0',
                        'sort_order' => $order,
                    ]);
                }

                foreach ($this->tariffLines as $order => $tariff) {
                    $isBlankRow = trim((string) ($tariff['tariff_code'] ?? '')) === ''
                        && trim((string) ($tariff['foreign_amount'] ?? '')) === ''
                        && (float) ($tariff['customs_duty'] ?? 0) === 0.0
                        && (float) ($tariff['vat_amount'] ?? 0) === 0.0;

                    if ($isBlankRow) {
                        continue;
                    }

                    $invoice->tariffLines()->create([
                        'tariff_code' => $tariff['tariff_code'] ?: '—',
                        'foreign_amount' => filled($tariff['foreign_amount'] ?? null) ? $tariff['foreign_amount'] : null,
                        'customs_duty' => $tariff['customs_duty'] ?: '0',
                        'vat_amount' => $tariff['vat_amount'] ?: '0',
                        'sort_order' => $order,
                    ]);
                }
            }

            $this->purchaseInvoice = $invoice;
        });

        return true;
    }

    /** Зачувај како нацрт. */
    public function save(): void
    {
        if ($this->persist()) {
            $this->redirect(route('purchase-invoices.show', [$this->company, $this->purchaseInvoice]));
        }
    }

    /**
     * Зачувај и потврди: нацртот се зачувува, па се потврдува (книжење,
     * залиха). Ако потврдата не успее, нацртот останува зачуван и грешката се
     * гледа на формата — фактурата не се губи.
     */
    public function saveAndConfirm(PurchaseInvoiceService $service): void
    {
        if (! $this->persist()) {
            return;
        }

        try {
            $service->confirm($this->purchaseInvoice, auth()->id());
        } catch (InsufficientStockException|InvalidInvoiceStateException $e) {
            $this->addError('confirm', $e->getMessage().' Фактурата е зачувана како нацрт.');

            return;
        }

        $this->redirect(route('purchase-invoices.show', [$this->company, $this->purchaseInvoice]));
    }

    /** Избран добавувач со договорен рок го полни рокот на плаќање. */
    public function updatedPartnerId(string $value): void
    {
        $partner = Partner::where('company_id', $this->company->id)->find($value);

        if ($partner?->payment_terms_days === null) {
            return;
        }

        $this->paymentTermsDays = (string) $partner->payment_terms_days;
        $this->applyPaymentTerms();
    }

    /** Избран рок на плаќање ја поставува датата на доспевање од датумот на фактурата. */
    public function updatedPaymentTermsDays(string $value): void
    {
        $this->applyPaymentTerms();
    }

    /** Со промена на датумот на фактурата, избраниот рок го носи и доспевањето. */
    public function updatedInvoiceDate(string $value): void
    {
        $this->applyPaymentTerms();
    }

    private function applyPaymentTerms(): void
    {
        if (! is_numeric($this->paymentTermsDays) || ! filled($this->invoiceDate)) {
            return;
        }

        try {
            $this->dueDate = Carbon::parse($this->invoiceDate)->addDays((int) $this->paymentTermsDays)->toDateString();
        } catch (\Throwable) {
            // Невалиден датум: валидацијата при зачувување го фаќа.
        }
    }

    /**
     * Кратка информација за избраниот добавувач: адреса, е-пошта и колку му должиме.
     *
     * @return array{partner: Partner, payables: string}|null
     */
    private function partnerInfo(): ?array
    {
        if (! is_numeric($this->partnerId)) {
            return null;
        }

        $partner = Partner::where('company_id', $this->company->id)->find((int) $this->partnerId);

        return $partner ? ['partner' => $partner, 'payables' => PartnerInsights::payables($partner)] : null;
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

        $stockLines = [];
        foreach ($rows as $index => $row) {
            if ($row['is_stock']) {
                // Внесена рачно, па можеби со запирка наместо точка (МК
                // навика) — иста нормализација како секоја друга бруто/нето
                // цена на оваа форма пред да допре bcmath, инаку bcdiv/bccomp
                // во LandedCostAllocator паѓаат со ValueError.
                $quantity = VatMath::number((string) ($this->lines[$index]['quantity'] ?? '0'));
                $stockLines[(string) $index] = ['net' => $row['net'], 'quantity' => $quantity];
            }
        }

        // Секој внесен износ прво се нормализира (запирка → точка), па се
        // заокружува half-up на 2 децимали ПРЕД да се собере — bcadd со
        // scale=2 отсекува, не заокружува, па „10,555" инаку би влегол во
        // прегледот како 10.55 додека базата (при зачувување) го заокружува
        // на 10.56. Истото правило како VatMath насекаде во оваа форма.
        $importCostsBase = collect($this->importCosts)->reduce(
            fn (?string $carry, array $cost) => bcadd($carry ?? '0.00', Bcmath::roundHalfUp(VatMath::number($cost['base_amount'] ?? '0'), 2), 2),
            '0.00'
        );
        $tariffDutyTotal = collect($this->tariffLines)->reduce(
            fn (?string $carry, array $tariff) => bcadd($carry ?? '0.00', Bcmath::roundHalfUp(VatMath::number($tariff['customs_duty'] ?? '0'), 2), 2),
            '0.00'
        );
        $totalForAllocation = bcadd($importCostsBase, $tariffDutyTotal, 2);

        $landedUnitCosts = $this->isImport
            ? app(LandedCostAllocator::class)->allocate($stockLines, $totalForAllocation)
            : [];

        $items = Item::where('company_id', $this->company->id)->where('is_active', true)
            ->where(fn ($q) => $q->where('is_purchasable', true)->orWhereIn('id', collect($this->lines)->pluck('item_id')->filter()->all()))
            ->orderBy('type')->orderBy('name')->get();

        return view('livewire.invoicing.purchase-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => $items,
            // Преглед по ставка (Продажна колона) бара брзо гледање по
            // артикл без нова query по ред — истата колекција веќе ја имаме.
            'itemsById' => $items->keyBy('id'),
            'accounts' => Account::where('company_id', $this->company->id)->postable()->where('is_active', true)->orderBy('code')->get(),
            'rows' => $rows,
            'landedUnitCosts' => $landedUnitCosts,
            'importCostsBase' => $importCostsBase,
            'tariffDutyTotal' => $tariffDutyTotal,
            'totalForAllocation' => $totalForAllocation,
            'partnerInfo' => $this->partnerInfo(),
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
