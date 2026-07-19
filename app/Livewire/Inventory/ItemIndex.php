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
        ]);

        Item::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'unit_of_measure' => $validated['newUnitOfMeasure'],
            'category' => $validated['newCategory'] ?: null,
            'vat_rate' => $validated['newVatRate'],
            'preferred_partner_id' => $validated['newPreferredPartnerId'] ?: null,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newCategory', 'newPreferredPartnerId']);
        $this->newUnitOfMeasure = 'piece';
        $this->newVatRate = '18.00';
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $item->update(['is_active' => ! $item->is_active]);
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
