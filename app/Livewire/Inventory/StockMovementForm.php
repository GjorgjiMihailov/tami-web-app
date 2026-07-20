<?php

namespace App\Livewire\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockMovementForm extends Component
{
    private const VALID_TYPES = ['receipt', 'issue', 'transfer', 'adjustment'];

    public Company $company;

    public string $type;

    public string $itemId = '';

    public string $warehouseId = '';

    public string $toWarehouseId = '';

    public string $quantity = '';

    public string $unitCost = '';

    public string $direction = 'increase';

    public string $reason = '';

    public string $movementDate = '';

    public function mount(Company $company, string $type): void
    {
        Gate::authorize('view', $company);

        if (! in_array($type, self::VALID_TYPES, true)) {
            abort(404);
        }

        $this->company = $company;
        $this->type = $type;
        $this->movementDate = now()->toDateString();
    }

    public function lookupByCode(string $code): void
    {
        $item = Item::where('company_id', $this->company->id)->where('code', $code)->first();

        if (! $item) {
            $this->addError('scannedCode', "No item found with code \"{$code}\".");

            return;
        }

        $this->itemId = (string) $item->id;
        $this->resetErrorBag('scannedCode');
    }

    public function save(): void
    {
        Gate::authorize('create', StockMovement::class);

        $rules = [
            'itemId' => ['required', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'movementDate' => 'required|date',
            'quantity' => 'required|numeric|gt:0',
        ];

        if ($this->type === 'receipt') {
            $rules['unitCost'] = 'required|numeric|min:0';
        }

        if ($this->type === 'transfer') {
            $rules['toWarehouseId'] = [
                'required',
                Rule::exists('warehouses', 'id')->where('company_id', $this->company->id),
                'different:warehouseId',
            ];
        }

        if ($this->type === 'adjustment') {
            $rules['reason'] = 'required|string|max:255';
        }

        $this->validate($rules);

        $item = Item::findOrFail($this->itemId);
        $warehouse = Warehouse::findOrFail($this->warehouseId);
        $service = app(StockMovementService::class);
        $userId = auth()->id();

        try {
            match ($this->type) {
                'receipt' => $service->receipt($item, $warehouse, $this->quantity, $this->unitCost, $this->movementDate, $userId),
                'issue' => $service->issue($item, $warehouse, $this->quantity, $this->movementDate, $userId),
                'transfer' => $service->transfer($item, $warehouse, Warehouse::findOrFail($this->toWarehouseId), $this->quantity, $this->movementDate, $userId),
                'adjustment' => $service->adjustment(
                    $item,
                    $warehouse,
                    $this->direction === 'decrease' ? '-'.$this->quantity : $this->quantity,
                    $this->reason,
                    $this->movementDate,
                    $userId
                ),
            };
        } catch (InsufficientStockException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->redirect(route('inventory.items.index', $this->company));
    }

    public function render()
    {
        return view('livewire.inventory.stock-movement-form', [
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
