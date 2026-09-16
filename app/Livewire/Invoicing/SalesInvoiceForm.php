<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Warehouse;
use App\Services\ExchangeRateService;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Support\InvoiceLanguage;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class SalesInvoiceForm extends Component
{
    use WithFileUploads;

    public Company $company;

    public ?SalesInvoice $salesInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    /**
     * Бројот што фактурата веќе го носи на хартија.
     *
     * Празно значи обична фактура — бројот го дава серијата на фирмата при
     * потврда. Пополнето значи фактура внесена од скен и тој број оди и на
     * печатената фактура и кон УЈП.
     */
    public string $paperNumber = '';

    public $scanFile = null;

    /**
     * Дали формата е пополнета од скен. Го отклучува полето за хартиениот број
     * и жолтата лента „провери пред потврда“.
     */
    public bool $scanRead = false;

    /** @var string[] Предупредувања од проверките врз прочитаното. */
    public array $scanWarnings = [];

    public string $paymentTypeCode = 'P12';

    public string $currency = 'MKD';

    public string $exchangeRate = '1';

    public array $lines = [];

    public int $workingYear = 0;

    public function mount(Company $company, ?SalesInvoice $salesInvoice = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);

        Gate::authorize($salesInvoice ? 'update' : 'create', $salesInvoice ?? SalesInvoice::class);

        if ($salesInvoice) {
            if ($salesInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($salesInvoice->status !== 'draft') {
                abort(403, 'Можат да се менуваат само нацрт фактури.');
            }
        }

        $this->salesInvoice = $salesInvoice;

        if ($salesInvoice) {
            $this->partnerId = (string) $salesInvoice->partner_id;
            $this->warehouseId = $salesInvoice->warehouse_id === null ? '' : (string) $salesInvoice->warehouse_id;
            $this->invoiceDate = $salesInvoice->invoice_date->toDateString();
            $this->dueDate = $salesInvoice->due_date->toDateString();
            $this->notes = (string) $salesInvoice->notes;
            $this->paperNumber = (string) $salesInvoice->invoice_number_formatted;
            $this->paymentTypeCode = $salesInvoice->payment_type_code;
            $this->currency = $salesInvoice->currency;
            $this->exchangeRate = (string) $salesInvoice->exchange_rate;
            $this->lines = $salesInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'vat_rate' => (string) $line->vat_rate,
                'vat_treatment' => (string) $line->vat_treatment,
            ])->toArray();
        } else {
            $this->invoiceDate = WorkingYear::defaultDate($this->workingYear);
            $this->dueDate = WorkingYear::defaultDate($this->workingYear);
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
            'vat_treatment' => 'standard',
        ];
    }

    /**
     * Кога се менува валутата, се нуди последниот курс што фирмата го користела
     * за неа. Курсот останува рачен — ова е само понуда, не автоматика.
     */
    public function updatedCurrency(string $value): void
    {
        // Livewire ја памети грешката на exchangeRate меѓу барањата — стар
        // неуспех од НБРМ не смее да остане прикажан откако валутата (или
        // курсот што ѝ следи) е веќе сменета.
        $this->resetErrorBag('exchangeRate');

        if ($value === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        $last = SalesInvoice::where('company_id', $this->company->id)
            ->where('currency', $value)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->value('exchange_rate');

        $this->exchangeRate = $last === null ? '' : (string) $last;
    }

    /**
     * Копчето „НБРМ“ — истиот сервис што го користи формата за рачно книжење.
     *
     * Полето останува рачно и по ова: паднат НБРМ не смее да блокира издавање
     * фактура, па неуспехот се прикажува како грешка на полето, а корисникот
     * впишува курс сам.
     */
    public function fetchRate(): void
    {
        if ($this->currency === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        try {
            $rate = app(ExchangeRateService::class)->getRate(
                $this->currency,
                Carbon::parse($this->invoiceDate)
            );
        } catch (\Throwable $e) {
            $this->addError('exchangeRate', 'Курсот не се презеде од НБРМ — впиши го рачно.');

            return;
        }

        // Успешен обид по претходен неуспех не смее да остави стара грешка
        // под точниот курс.
        $this->resetErrorBag('exchangeRate');
        $this->exchangeRate = (string) $rate;
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

            if ($item->selling_price !== null) {
                $this->lines[$index]['unit_price'] = (string) $item->selling_price;
            }
        }
    }

    public function setVatTreatment(int $index, string $treatment): void
    {
        $this->lines[$index]['vat_treatment'] = $treatment;

        if ($treatment !== 'standard') {
            $this->lines[$index]['vat_rate'] = '0.00';
        }
    }

    /**
     * Читањето чини пари од буџетот на канцеларијата, па клиентите остануваат
     * надвор иако смеат да создаваат фактури. Без клуч функцијата воопшто ја
     * нема — сервер без клуч работи како досега.
     */
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

        $this->validate([
            'scanFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

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
     * Прочитаното се прелива во формата. Секое поле е незадолжително: ако Claude
     * не прочитал нешто, старата вредност останува и човекот ја дополнува.
     */
    private function applyScan(ScannedInvoice $scanned): void
    {
        if (filled($scanned->invoiceNumber)) {
            $this->paperNumber = substr($scanned->invoiceNumber, 0, 40);
        }

        if (filled($scanned->invoiceDate)) {
            $this->invoiceDate = $scanned->invoiceDate;
        }

        if (filled($scanned->dueDate)) {
            $this->dueDate = $scanned->dueDate;
        }

        if (filled($scanned->currency) && in_array($scanned->currency, SalesInvoice::CURRENCIES, true)) {
            $this->currency = $scanned->currency;
        }

        if (filled($scanned->buyerTaxId)) {
            $partner = Partner::where('company_id', $this->company->id)
                ->where('tax_id', $scanned->buyerTaxId)
                ->first();

            if ($partner) {
                $this->partnerId = (string) $partner->id;
            }
        }

        if ($scanned->lines !== []) {
            $this->lines = array_map(fn (ScannedInvoiceLine $line) => [
                // Ставките од скен се секогаш слободен текст. Врзувањето за
                // артикл од шифрарникот би повлекло и поместување залиха за
                // стока што веќе е издадена надвор од Тами.
                'item_id' => '',
                'description' => (string) $line->description,
                'quantity' => filled($line->quantity) ? $line->quantity : '1',
                'unit_price' => filled($line->unitPrice) ? $line->unitPrice : '0',
                'vat_rate' => filled($line->vatRate) ? $line->vatRate : '0.00',
                // Ослободувањата и преносот на обврска не ги погодува машина.
                'vat_treatment' => 'standard',
            ], $scanned->lines);
        }
    }

    public function save(): void
    {
        Gate::authorize($this->salesInvoice ? 'update' : 'create', $this->salesInvoice ?? SalesInvoice::class);

        // Девизна фактура важи само за физичко лице. Скриено поле во Blade не е
        // заклучување — тоа се прави овде, пред валидацијата.
        if (! $this->company->type->isIndividual()) {
            $this->currency = 'MKD';
            $this->exchangeRate = '1';
        }

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'paperNumber' => 'nullable|string|max:40',
            'paymentTypeCode' => ['required', Rule::in(array_keys(SalesInvoice::PAYMENT_TYPES))],
            'currency' => ['required', Rule::in(SalesInvoice::CURRENCIES)],
            'exchangeRate' => [
                $this->currency === 'MKD' ? 'nullable' : 'required',
                'numeric',
                'gt:0',
            ],
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
            'lines.*.vat_treatment' => ['required', Rule::in(SalesInvoiceLine::TREATMENTS)],
        ]);

        // Двоен испишан број во иста фирма и иста година е вистински проблем —
        // кон УЈП би заминале две фактури со ист `docNumber`. Годината се зема
        // од датумот на фактурата, бидејќи `fiscal_year` се полни дури при
        // потврда, а проверката мора да важи и на нацрт.
        if ($this->paperNumber !== '') {
            $clash = SalesInvoice::where('company_id', $this->company->id)
                ->where('invoice_number_formatted', $this->paperNumber)
                ->whereYear('invoice_date', Carbon::parse($this->invoiceDate)->year)
                ->when($this->salesInvoice, fn ($query) => $query->whereKeyNot($this->salesInvoice->id))
                ->exists();

            if ($clash) {
                $this->addError('paperNumber', "Веќе постои фактура со број {$this->paperNumber} во таа година.");

                return;
            }
        }

        foreach ($this->lines as $index => $line) {
            if (($line['vat_treatment'] ?? 'standard') !== 'standard') {
                $this->lines[$index]['vat_rate'] = '0.00';
            }
        }

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') === '' && trim((string) ($line['description'] ?? '')) === '') {
                $this->addError("lines.{$index}.description", 'Секоја ставка мора да содржи артикл или опис.');

                return;
            }
        }

        $hasItemLines = collect($this->lines)->contains(fn ($line) => ($line['item_id'] ?? '') !== '');

        if ($hasItemLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'Потребен е магацин кога некоја ставка содржи артикл.');

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
            $invoice->invoice_number_formatted = $this->paperNumber ?: null;
            $invoice->payment_type_code = $this->paymentTypeCode;
            $invoice->currency = $this->currency;
            $invoice->exchange_rate = $this->currency === 'MKD' ? '1' : $this->exchangeRate;

            // Јазикот се презема од кооперантот на секое зачувување на нацртот,
            // па промена на купувачот го носи и неговиот јазик. По потврда
            // фактурата повеќе не поминува одовде и текстот останува замрзнат.
            $partner = Partner::where('company_id', $this->company->id)->find($this->partnerId);
            $invoice->language = $this->company->type->isIndividual() && $partner
                ? $partner->invoice_language
                : InvoiceLanguage::MK;

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
                    'vat_treatment' => $line['vat_treatment'] ?? 'standard',
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
