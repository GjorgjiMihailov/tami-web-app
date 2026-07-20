<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Services\Inventory\StockLevelQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockValuationReport extends Component
{
    public Company $company;

    public string $groupBy = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.inventory.stock-valuation-report', [
            'rows' => StockLevelQuery::valuationSummary($this->company, $this->groupBy ?: null),
        ]);
    }
}
