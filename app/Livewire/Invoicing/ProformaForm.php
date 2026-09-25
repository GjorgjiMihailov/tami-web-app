<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\SalesInvoice;
use App\Services\Invoicing\ProformaService;
use App\Support\VatMath;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProformaForm extends Component
{
    public Company $company;

    public ?ProformaInvoice $proforma = null;

    public string $partnerId = '';

    public string $reference = '';

    public string $proformaDate = '';

    public string $expectedDeliveryDate = '';

    public string $paymentTermsDays = '';

    public string $currency = 'MKD';

    public string $notes = '';

    public string $terms = '';

    public array $lines = [];

    public function mount(Company $company, ?ProformaInvoice $proforma = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        if ($proforma === null) {
            Gate::authorize('create', ProformaInvoice::class);

            $this->proformaDate = now()->toDateString();
            $this->lines = [$this->emptyLine()];

            // „Нова профактура“ од екранот на кооперантот го носи купувачот однапред.
            $requested = request()->query('partner');
            if (is_numeric($requested) && Partner::where('company_id', $company->id)->whereKey((int) $requested)->exists()) {
                $this->partnerId = (string) (int) $requested;
                $this->updatedPartnerId($this->partnerId);
            }

            return;
        }

        Gate::authorize('update', $proforma);

        // URL-от носи две независни id-ња — профактурата мора да е на оваа фирма.
        if ($proforma->company_id !== $company->id) {
            abort(404);
        }

        // Претворена или откажана профактура не се менува.
        abort_unless($proforma->isOpen(), 403);

        $this->proforma = $proforma;
        $this->partnerId = (string) $proforma->partner_id;
        $this->reference = (string) $proforma->reference;
        $this->proformaDate = $proforma->proforma_date->toDateString();
        $this->expectedDeliveryDate = $proforma->expected_delivery_date?->toDateString() ?? '';
        $this->paymentTermsDays = $proforma->payment_terms_days === null ? '' : (string) $proforma->payment_terms_days;
        $this->currency = $proforma->currency;
        $this->notes = (string) $proforma->notes;
        $this->terms = (string) $proforma->terms;
        $this->lines = $proforma->lines->map(fn ($line) => [
            'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'vat_rate' => (string) $line->vat_rate,
        ])->all();

        if ($this->lines === []) {
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

        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
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

        $this->lines[$index]['description'] = $item->name;
        $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';

        if ($item->selling_price !== null) {
            $this->lines[$index]['unit_price'] = (string) $item->selling_price;
        }
    }

    /** Рокот на плаќање на купувачот се презема, но човекот може да го смени. */
    public function updatedPartnerId(string $value): void
    {
        $partner = Partner::where('company_id', $this->company->id)->find($value);

        if ($partner?->payment_terms_days !== null) {
            $this->paymentTermsDays = (string) $partner->payment_terms_days;
        }
    }

    /** Зачувај како нацрт. */
    public function saveDraft()
    {
        return $this->persist(false);
    }

    /** Зачувај — нова профактура станува потврдена; веќе потврдена си останува. */
    public function save()
    {
        return $this->persist(true);
    }

    private function persist(bool $confirm)
    {
        Gate::authorize($this->proforma ? 'update' : 'create', $this->proforma ?? ProformaInvoice::class);

        // Девизна профактура важи само за физичко лице, исто како фактурата.
        // Скриено поле во Blade не е заклучување — тоа се прави овде.
        if (! $this->company->type->isIndividual()) {
            $this->currency = 'MKD';
        }

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'reference' => 'nullable|string|max:100',
            'proformaDate' => 'required|date',
            'expectedDeliveryDate' => 'nullable|date|after_or_equal:proformaDate',
            'paymentTermsDays' => 'nullable|integer|min:0|max:365',
            'currency' => ['required', Rule::in(SalesInvoice::CURRENCIES)],
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:4000',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
        ]);

        $vatRegistered = (bool) $this->company->is_vat_registered;

        $lines = collect($this->lines)->map(fn (array $line) => [
            'item_id' => $line['item_id'] !== '' ? (int) $line['item_id'] : null,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            // Фирма што не е ДДВ обврзник не пресметува ДДВ, без оглед на полето.
            'vat_rate' => $vatRegistered ? $line['vat_rate'] : '0.00',
        ])->all();

        $attributes = [
            'partner_id' => (int) $this->partnerId,
            'reference' => $this->reference ?: null,
            'proforma_date' => $this->proformaDate,
            'expected_delivery_date' => $this->expectedDeliveryDate ?: null,
            'payment_terms_days' => $this->paymentTermsDays === '' ? null : (int) $this->paymentTermsDays,
            'currency' => $this->currency,
            'notes' => $this->notes ?: null,
            'terms' => $this->terms ?: null,
        ];

        $service = app(ProformaService::class);

        try {
            if ($this->proforma) {
                $proforma = $service->update($this->proforma, $attributes, $lines);
            } else {
                $proforma = $service->create($this->company, $attributes + ['status' => 'draft', 'created_by' => auth()->id()], $lines);
            }

            if ($confirm && $proforma->status === 'draft') {
                $service->confirm($proforma);
            }
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('lines', $e->getMessage());

            return null;
        }

        return $this->redirect(route('proformas.index', [$this->company, 'proforma' => $proforma->id]), navigate: true);
    }

    public function render()
    {
        $vatRegistered = (bool) $this->company->is_vat_registered;
        $net = '0.00';
        $vat = '0.00';
        $rows = [];

        foreach ($this->lines as $index => $line) {
            $rate = $vatRegistered ? (string) ($line['vat_rate'] ?? '0') : '0';
            $amounts = VatMath::lineFromNet((string) ($line['quantity'] ?? '0'), (string) ($line['unit_price'] ?? '0'), $rate);
            $rows[$index] = $amounts;
            $net = bcadd($net, $amounts['net'], 2);
            $vat = bcadd($vat, $amounts['vat'], 2);
        }

        return view('livewire.invoicing.proforma-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)
                ->where(fn ($q) => $q->where('is_sellable', true)->orWhereIn('id', collect($this->lines)->pluck('item_id')->filter()->all()))
                ->orderBy('type')->orderBy('name')->get(),
            'vatRegistered' => $vatRegistered,
            'rows' => $rows,
            'moneyLabel' => $this->currency === 'MKD' ? 'ден' : $this->currency,
            'totals' => ['net' => $net, 'vat' => $vat, 'gross' => bcadd($net, $vat, 2)],
        ]);
    }
}
