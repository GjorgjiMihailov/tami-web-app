<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Support\VatMath;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemForm extends Component
{
    /** Предлог-листа за мерна единица; на неа се додаваат вредностите што фирмата веќе ги користи. */
    public const UNIT_SUGGESTIONS = ['бр.', 'кг', 'г', 'л', 'м', 'м²', 'м³', 'пар', 'кутија', 'пакување', 'час', 'ден', 'месец'];

    public Company $company;

    public ?Item $item = null;

    public string $name = '';

    public string $type = 'product';

    public string $category = '';

    public string $unitOfMeasure = 'бр.';

    public string $code = '';

    public string $barcode = '';

    public string $description = '';

    public bool $isMadeInMk = false;

    public bool $isSellable = true;

    public string $sellingPrice = '';

    /** Цена со ДДВ; се чува само кога е внесена токму таа (sellingBasis = gross). */
    public string $sellingPriceGross = '';

    /** Која од двете цени ја внел човекот: 'net' | 'gross'. */
    public string $sellingBasis = 'net';

    public string $vatRate = '18.00';

    public bool $isPurchasable = true;

    public string $costPrice = '';

    public string $costPriceGross = '';

    public string $costBasis = 'net';

    /** Празно = истиот ДДВ како при продажба. */
    public string $purchaseVatRate = '';

    public string $preferredPartnerId = '';

    public function mount(Company $company, ?Item $item = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        if ($item === null) {
            Gate::authorize('create', Item::class);

            return;
        }

        Gate::authorize('update', $item);

        // URL-от носи две независни id-ња; без ова, некој што гледа две фирми
        // би можел да отвори артикл на фирма Б под фирма А и со зачувување да го премести.
        if ($item->company_id !== $company->id) {
            abort(404);
        }

        $this->item = $item;
        $this->name = $item->name;
        $this->type = $item->type;
        $this->category = (string) $item->category;
        $this->unitOfMeasure = $item->unit_of_measure;
        $this->code = $item->code;
        $this->barcode = (string) $item->barcode;
        $this->description = (string) $item->description;
        $this->isMadeInMk = $item->is_made_in_mk;
        $this->isSellable = $item->is_sellable;
        $this->sellingPrice = (string) $item->selling_price;
        $this->sellingBasis = $item->selling_price_gross !== null ? 'gross' : 'net';
        $this->sellingPriceGross = $item->selling_price_gross !== null
            ? (string) $item->selling_price_gross
            : ($this->sellingPrice !== '' ? VatMath::grossFromNet($this->sellingPrice, (string) $item->vat_rate) : '');
        $this->vatRate = (string) $item->vat_rate;
        $this->isPurchasable = $item->is_purchasable;
        $this->costPrice = (string) $item->cost_price;
        $this->costBasis = $item->cost_price_gross !== null ? 'gross' : 'net';
        $this->costPriceGross = $item->cost_price_gross !== null
            ? (string) $item->cost_price_gross
            : ($this->costPrice !== '' ? VatMath::grossFromNet($this->costPrice, $item->purchaseVatRate()) : '');
        $this->purchaseVatRate = (string) $item->purchase_vat_rate;
        $this->preferredPartnerId = (string) $item->preferred_partner_id;
    }

    /**
     * Нето и бруто цената се држат една со друга, но полето во кое човекот
     * пишува никогаш не се преправа под неговите прсти — исто правило како на
     * ставка од фактура. Промена на ДДВ ја пресметува изведената цена.
     */
    public function updated(string $name): void
    {
        match ($name) {
            'sellingPrice' => $this->typed('selling', 'net'),
            'sellingPriceGross' => $this->typed('selling', 'gross'),
            'costPrice' => $this->typed('cost', 'net'),
            'costPriceGross' => $this->typed('cost', 'gross'),
            'vatRate' => $this->refreshBoth(),
            'purchaseVatRate' => $this->refresh('cost'),
            default => null,
        };
    }

    private function typed(string $which, string $basis): void
    {
        if ($which === 'selling') {
            $this->sellingBasis = $basis;
        } else {
            $this->costBasis = $basis;
        }

        $this->refresh($which);
    }

    private function refreshBoth(): void
    {
        $this->refresh('selling');
        $this->refresh('cost');
    }

    /** Го пресметува полето што НЕ е основата на внесот. */
    private function refresh(string $which): void
    {
        $selling = $which === 'selling';
        $basis = $selling ? $this->sellingBasis : $this->costBasis;
        $rate = $selling ? $this->vatRate : $this->effectivePurchaseRate();
        $net = $selling ? $this->sellingPrice : $this->costPrice;
        $gross = $selling ? $this->sellingPriceGross : $this->costPriceGross;

        if ($basis === 'gross') {
            $derived = trim($gross) === '' ? '' : VatMath::netFromGross($gross, $rate);

            if ($selling) {
                $this->sellingPrice = $derived;
            } else {
                $this->costPrice = $derived;
            }

            return;
        }

        $derived = trim($net) === '' ? '' : VatMath::grossFromNet($net, $rate);

        if ($selling) {
            $this->sellingPriceGross = $derived;
        } else {
            $this->costPriceGross = $derived;
        }
    }

    private function effectivePurchaseRate(): string
    {
        return trim($this->purchaseVatRate) !== '' ? $this->purchaseVatRate : $this->vatRate;
    }

    public function save()
    {
        if ($this->item) {
            Gate::authorize('update', $this->item);
        } else {
            Gate::authorize('create', Item::class);
        }

        $this->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(Item::TYPES)],
            'category' => 'nullable|string|max:255',
            'unitOfMeasure' => 'required|string|max:20',
            'code' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->where('company_id', $this->company->id)->ignore($this->item?->id)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('items', 'barcode')->where('company_id', $this->company->id)->ignore($this->item?->id)],
            'description' => 'nullable|string|max:2000',
            'isMadeInMk' => 'boolean',
            'isSellable' => 'boolean',
            'sellingPrice' => 'nullable|numeric|min:0',
            'sellingPriceGross' => 'nullable|numeric|min:0',
            'vatRate' => 'required|numeric|min:0|max:100',
            'isPurchasable' => 'boolean',
            'costPrice' => 'nullable|numeric|min:0',
            'costPriceGross' => 'nullable|numeric|min:0',
            'purchaseVatRate' => 'nullable|numeric|min:0|max:100',
            'preferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
        ]);

        // Основата на внесот е вистината: изведеното нето се пресметува пак на
        // зачувување, за да не зависи од тоа дали последното барање го освежило.
        $this->refreshBoth();

        $attributes = [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'unit_of_measure' => $this->unitOfMeasure,
            'category' => $this->category ?: null,
            'type' => $this->type,
            'is_made_in_mk' => $this->isMadeInMk,
            'barcode' => $this->barcode ?: null,
            'is_sellable' => $this->isSellable,
            'selling_price' => $this->sellingPrice !== '' ? $this->sellingPrice : null,
            'selling_price_gross' => $this->sellingBasis === 'gross' && $this->sellingPriceGross !== '' ? $this->sellingPriceGross : null,
            'vat_rate' => $this->vatRate,
            'is_purchasable' => $this->isPurchasable,
            'cost_price' => $this->costPrice !== '' ? $this->costPrice : null,
            'cost_price_gross' => $this->costBasis === 'gross' && $this->costPriceGross !== '' ? $this->costPriceGross : null,
            'purchase_vat_rate' => $this->purchaseVatRate !== '' ? $this->purchaseVatRate : null,
            'preferred_partner_id' => $this->preferredPartnerId ?: null,
        ];

        if ($this->item) {
            $this->item->update($attributes);
        } else {
            Item::create($attributes + ['company_id' => $this->company->id, 'is_active' => true]);
        }

        $saved = $this->item ?? Item::where('company_id', $this->company->id)->where('code', $this->code)->first();

        return $this->redirect(route('inventory.items.index', [$this->company, 'item' => $saved->id]), navigate: true);
    }

    public function render()
    {
        $companyItems = Item::where('company_id', $this->company->id);

        return view('livewire.inventory.item-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'categories' => (clone $companyItems)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'units' => collect(self::UNIT_SUGGESTIONS)
                ->merge((clone $companyItems)->distinct()->pluck('unit_of_measure'))
                ->unique()->values(),
        ]);
    }
}
