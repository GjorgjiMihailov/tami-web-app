<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WarehouseIndex extends Component
{
    public Company $company;

    public string $newName = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addWarehouse(): void
    {
        Gate::authorize('create', Warehouse::class);

        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->where('company_id', $this->company->id)],
        ]);

        Warehouse::create([
            'company_id' => $this->company->id,
            'name' => $validated['newName'],
            'is_active' => true,
        ]);

        $this->reset(['newName']);
    }

    public function toggleActive(int $warehouseId): void
    {
        $warehouse = Warehouse::where('company_id', $this->company->id)->findOrFail($warehouseId);
        Gate::authorize('update', $warehouse);

        $warehouse->update(['is_active' => ! $warehouse->is_active]);
    }

    public function render()
    {
        return view('livewire.inventory.warehouse-index', [
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
