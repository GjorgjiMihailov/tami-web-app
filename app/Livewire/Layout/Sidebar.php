<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use Livewire\Component;

class Sidebar extends Component
{
    public ?string $expandedModule = null;

    public bool $recordMovementExpanded = false;

    public function mount(): void
    {
        $this->expandedModule = $this->moduleMatchingCurrentRoute();
        $this->recordMovementExpanded = request()->routeIs('inventory.stock-movements.create');
    }

    public function toggleModule(string $module): void
    {
        $this->expandedModule = $this->expandedModule === $module ? null : $module;

        if ($this->expandedModule !== 'inventory') {
            $this->recordMovementExpanded = false;
        }
    }

    public function toggleRecordMovement(): void
    {
        $this->recordMovementExpanded = ! $this->recordMovementExpanded;
    }

    private function moduleMatchingCurrentRoute(): ?string
    {
        return match (true) {
            request()->routeIs('accounting.*') => 'accounting',
            request()->routeIs('inventory.*') => 'inventory',
            request()->routeIs('partners.*'), request()->routeIs('sales-invoices.*'), request()->routeIs('purchase-invoices.*') => 'invoicing',
            default => null,
        };
    }

    public function render()
    {
        $company = request()->route('company');

        return view('livewire.layout.sidebar', [
            'company' => $company instanceof Company ? $company : null,
        ]);
    }
}
