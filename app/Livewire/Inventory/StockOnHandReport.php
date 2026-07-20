<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockOnHandReport extends Component
{
    public Company $company;

    public ?int $warehouseId = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.inventory.stock-on-hand-report', [
            'rows' => StockLevelQuery::stockOnHand($this->company, $this->warehouseId),
            'totals' => StockLevelQuery::stockOnHandTotals($this->company),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
