<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

class StockLevelQuery
{
    public static function stockOnHand(Company $company, ?int $warehouseId = null): Collection
    {
        return StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id)
            ->when($warehouseId, fn ($q) => $q->where('stock_levels.warehouse_id', $warehouseId))
            ->orderBy('items.name')
            ->get([
                'items.id as item_id',
                'items.code as item_code',
                'items.name as item_name',
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name',
                'stock_levels.quantity_on_hand',
                'stock_levels.average_cost',
            ])
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'quantity_on_hand' => (float) $row->quantity_on_hand,
                'average_cost' => (float) $row->average_cost,
                'value' => round((float) $row->quantity_on_hand * (float) $row->average_cost, 2),
            ])
            ->values();
    }

    public static function stockOnHandTotals(Company $company): Collection
    {
        return StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id)
            ->selectRaw('items.id as item_id, items.code as item_code, items.name as item_name, SUM(stock_levels.quantity_on_hand) as total_quantity, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')
            ->groupBy('items.id', 'items.code', 'items.name')
            ->orderBy('items.name')
            ->get()
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'total_quantity' => (float) $row->total_quantity,
                'total_value' => round((float) $row->total_value, 2),
            ])
            ->values();
    }

    public static function valuationSummary(Company $company, ?string $groupBy = null): Collection
    {
        $query = StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id);

        if ($groupBy === 'warehouse') {
            return $query
                ->selectRaw('warehouses.name as label, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')
                ->groupBy('warehouses.id', 'warehouses.name')
                ->orderBy('warehouses.name')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'total_value' => round((float) $row->total_value, 2)])
                ->values();
        }

        if ($groupBy === 'category') {
            return $query
                ->selectRaw("COALESCE(items.category, 'Uncategorized') as label, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value")
                ->groupBy('label')
                ->orderBy('label')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'total_value' => round((float) $row->total_value, 2)])
                ->values();
        }

        $total = (clone $query)->selectRaw('SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')->value('total_value');

        return collect([['label' => 'Total', 'total_value' => round((float) $total, 2)]]);
    }
}
