<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Inventory\ItemMovementCardQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemMovementCardReport extends Component
{
    public Company $company;

    public ?int $itemId = null;

    public ?int $warehouseId = null;

    public string $from = '';

    public string $to = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $rows = collect();

        if ($this->itemId && $this->warehouseId) {
            $item = Item::where('company_id', $this->company->id)->findOrFail($this->itemId);
            $warehouse = Warehouse::where('company_id', $this->company->id)->findOrFail($this->warehouseId);

            $rows = ItemMovementCardQuery::run($item, $warehouse, [
                'from' => Carbon::parse($this->from),
                'to' => Carbon::parse($this->to),
            ]);
        }

        return view('livewire.inventory.item-movement-card-report', [
            'rows' => $rows,
            'items' => Item::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
