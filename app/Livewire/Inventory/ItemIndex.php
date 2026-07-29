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
class ItemIndex extends Component
{
    public Company $company;

    public string $search = '';

    public string $newCode = '';

    public string $newName = '';

    public string $newUnitOfMeasure = 'piece';

    public string $newCategory = '';

    public string $newVatRate = '18.00';

    public string $newPreferredPartnerId = '';

    public string $newSellingPrice = '';

    public string $newType = 'product';

    public bool $newIsMadeInMk = false;

    public string $newBarcode = '';

    public ?int $editingItemId = null;

    public string $editCode = '';

    public string $editName = '';

    public string $editUnitOfMeasure = '';

    public string $editCategory = '';

    public string $editVatRate = '';

    public string $editPreferredPartnerId = '';

    public string $editSellingPrice = '';

    public string $editType = 'product';

    public bool $editIsMadeInMk = false;

    public string $editBarcode = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addItem(): void
    {
        Gate::authorize('create', Item::class);

        $validated = $this->validate([
            'newCode' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->where('company_id', $this->company->id)],
            'newName' => 'required|string|max:255',
            'newUnitOfMeasure' => 'required|string|max:20',
            'newCategory' => 'nullable|string|max:255',
            'newVatRate' => 'required|numeric|min:0|max:100',
            'newPreferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'newSellingPrice' => 'nullable|numeric|min:0',
            'newType' => ['required', Rule::in(Item::TYPES)],
            'newIsMadeInMk' => 'boolean',
            'newBarcode' => ['nullable', 'string', 'max:50', Rule::unique('items', 'barcode')->where('company_id', $this->company->id)],
        ]);

        Item::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'unit_of_measure' => $validated['newUnitOfMeasure'],
            'category' => $validated['newCategory'] ?: null,
            'vat_rate' => $validated['newVatRate'],
            'preferred_partner_id' => $validated['newPreferredPartnerId'] ?: null,
            'selling_price' => $validated['newSellingPrice'] ?: null,
            'type' => $validated['newType'],
            'is_made_in_mk' => $validated['newIsMadeInMk'],
            'barcode' => $validated['newBarcode'] ?: null,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newCategory', 'newPreferredPartnerId', 'newSellingPrice', 'newIsMadeInMk', 'newBarcode']);
        $this->newUnitOfMeasure = 'piece';
        $this->newVatRate = '18.00';
        $this->newType = 'product';
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $item->update(['is_active' => ! $item->is_active]);
    }

    public function startEditingItem(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $this->resetErrorBag();
        $this->editingItemId = $itemId;
        $this->editCode = $item->code;
        $this->editName = $item->name;
        $this->editUnitOfMeasure = $item->unit_of_measure;
        $this->editCategory = (string) $item->category;
        $this->editVatRate = (string) $item->vat_rate;
        $this->editPreferredPartnerId = (string) $item->preferred_partner_id;
        $this->editSellingPrice = (string) $item->selling_price;
        $this->editType = $item->type;
        $this->editIsMadeInMk = $item->is_made_in_mk;
        $this->editBarcode = (string) $item->barcode;
    }

    public function cancelEditingItem(): void
    {
        $this->editingItemId = null;
    }

    public function updateItem(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $validated = $this->validate([
            'editCode' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->where('company_id', $this->company->id)->ignore($item->id)],
            'editName' => 'required|string|max:255',
            'editUnitOfMeasure' => 'required|string|max:20',
            'editCategory' => 'nullable|string|max:255',
            'editVatRate' => 'required|numeric|min:0|max:100',
            'editPreferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'editSellingPrice' => 'nullable|numeric|min:0',
            'editType' => ['required', Rule::in(Item::TYPES)],
            'editIsMadeInMk' => 'boolean',
            'editBarcode' => ['nullable', 'string', 'max:50', Rule::unique('items', 'barcode')->where('company_id', $this->company->id)->ignore($item->id)],
        ]);

        $item->update([
            'code' => $validated['editCode'],
            'name' => $validated['editName'],
            'unit_of_measure' => $validated['editUnitOfMeasure'],
            'category' => $validated['editCategory'] ?: null,
            'vat_rate' => $validated['editVatRate'],
            'preferred_partner_id' => $validated['editPreferredPartnerId'] ?: null,
            'selling_price' => $validated['editSellingPrice'] ?: null,
            'type' => $validated['editType'],
            'is_made_in_mk' => $validated['editIsMadeInMk'],
            'barcode' => $validated['editBarcode'] ?: null,
        ]);

        $this->editingItemId = null;
    }

    public function render()
    {
        $items = Item::where('company_id', $this->company->id)
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        return view('livewire.inventory.item-index', [
            'items' => $items,
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
