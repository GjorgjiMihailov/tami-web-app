<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Warehouse;
use App\Services\ExchangeRateService;
use App\Support\InvoiceLanguage;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
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
