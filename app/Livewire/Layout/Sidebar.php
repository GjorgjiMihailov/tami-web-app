<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Support\WorkingYear;
use Livewire\Component;

class Sidebar extends Component
{
    public ?Company $company = null;

    public ?string $expandedModule = null;

    public bool $recordMovementExpanded = false;

    public int $workingYear = 0;

    /** @var list<int> */
    public array $availableYears = [];

    // Everything this component needs is captured here, once. Reading
    // request()->route(...) from render() would silently lose the company on
    // the /livewire/update POST, which carries no route parameters.
    //
    // The optional $company parameter is never passed in production — the
    // layout renders <livewire:layout.sidebar /> with no arguments, so the
    // route fallback below runs. It exists so tests can mount the component
    // against a company without replaying a full page snapshot.
    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
        $this->expandedModule = $this->moduleMatchingCurrentRoute();
        $this->recordMovementExpanded = request()->routeIs('inventory.stock-movements.create');

        if ($this->company) {
            $this->workingYear = WorkingYear::for($this->company);
            $this->availableYears = WorkingYear::availableYears($this->company);
        }
    }

    // Livewire hands updated* hooks the raw incoming value, so cast before use
    // rather than type-hinting the parameter.
    public function updatedWorkingYear($value): void
    {
        $year = (int) $value;

        if (! $this->company || ! in_array($year, $this->availableYears, true)) {
            return;
        }

        WorkingYear::set($this->company, $year);

        $this->dispatch('working-year-changed', year: $year);
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
        return view('livewire.layout.sidebar', [
            'company' => $this->company,
        ]);
    }
}
