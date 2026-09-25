<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemIndex extends Component
{
    public Company $company;

    public string $search = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
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
        ]);
    }
}
