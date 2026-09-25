<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\ItemInsights;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemIndex extends Component
{
    public const FILTERS = ['active', 'inactive', 'all', 'product', 'service'];

    public const TABS = ['overview', 'transactions'];

    public Company $company;

    public string $search = '';

    #[Url(as: 'filter')]
    public string $filter = 'active';

    #[Url(as: 'item')]
    public ?int $selectedId = null;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $period = 'this_month';

    #[Url(as: 'tx')]
    public string $transactionFilter = 'all';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function select(int $itemId): void
    {
        $this->selectedId = Item::where('company_id', $this->company->id)->findOrFail($itemId)->id;
        $this->tab = 'overview';
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $item->update(['is_active' => ! $item->is_active]);
    }

    public function render()
    {
        // Вредностите од адресата не се доверливи — непозната се враќа на стандардна.
        $filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'active';
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $period = in_array($this->period, ItemInsights::PERIODS, true) ? $this->period : 'this_month';
        $transactionFilter = in_array($this->transactionFilter, ItemInsights::TRANSACTION_FILTERS, true) ? $this->transactionFilter : 'all';

        $items = Item::where('company_id', $this->company->id)
            ->when($filter === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($filter === 'product', fn ($q) => $q->where('type', 'product'))
            ->when($filter === 'service', fn ($q) => $q->where('type', 'service'))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        // Избраниот артикл се бара по фирма, не само по id — туѓо id од адресата дава празно.
        $selected = $this->selectedId
            ? Item::where('company_id', $this->company->id)->with('preferredPartner:id,name')->find($this->selectedId)
            : null;

        return view('livewire.inventory.item-index', [
            'items' => $items,
            'selected' => $selected,
            'filter' => $filter,
            'tab' => $tab,
            'period' => $period,
            'transactionFilter' => $transactionFilter,
            'sales' => $selected && $tab === 'overview' ? ItemInsights::salesSummary($selected, $period) : null,
            'stock' => $selected && $tab === 'overview' ? ItemInsights::stockByWarehouse($selected) : collect(),
            'transactions' => $selected && $tab === 'transactions' ? ItemInsights::transactions($selected, $transactionFilter) : collect(),
        ]);
    }
}
