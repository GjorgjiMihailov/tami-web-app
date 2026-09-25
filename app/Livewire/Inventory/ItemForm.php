<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
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

    public string $vatRate = '18.00';

    public bool $isPurchasable = true;

    public string $costPrice = '';

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
        $this->vatRate = (string) $item->vat_rate;
        $this->isPurchasable = $item->is_purchasable;
        $this->costPrice = (string) $item->cost_price;
        $this->purchaseVatRate = (string) $item->purchase_vat_rate;
        $this->preferredPartnerId = (string) $item->preferred_partner_id;
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
            'vatRate' => 'required|numeric|min:0|max:100',
            'isPurchasable' => 'boolean',
            'costPrice' => 'nullable|numeric|min:0',
            'purchaseVatRate' => 'nullable|numeric|min:0|max:100',
            'preferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
        ]);

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
            'vat_rate' => $this->vatRate,
            'is_purchasable' => $this->isPurchasable,
            'cost_price' => $this->costPrice !== '' ? $this->costPrice : null,
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
