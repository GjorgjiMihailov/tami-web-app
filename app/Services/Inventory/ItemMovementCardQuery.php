<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ItemMovementCardQuery
{
    public static function run(Item $item, Warehouse $warehouse, array $filters): Collection
    {
        /** @var Carbon $from */
        $from = $filters['from'];
        /** @var Carbon $to */
        $to = $filters['to'];

        $baseQuery = fn () => StockMovement::query()
            ->where('item_id', $item->id)
            ->where(function ($q) use ($warehouse) {
                $q->where('warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id);
            });

        $openingQuantity = (clone $baseQuery())
            ->where('movement_date', '<', $from->toDateString())
            ->get()
            ->sum(fn (StockMovement $m) => self::signedDelta($m, $warehouse->id));

        $movements = (clone $baseQuery())
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->with(['warehouse', 'toWarehouse'])
            ->get();

        $runningQuantity = $openingQuantity;

        return $movements->map(function (StockMovement $movement) use (&$runningQuantity, $warehouse) {
            $delta = self::signedDelta($movement, $warehouse->id);
            $runningQuantity += $delta;

            return [
                'date' => $movement->movement_date,
                'type' => $movement->type,
                'counterpart_warehouse' => $movement->warehouse_id === $warehouse->id
                    ? $movement->toWarehouse?->name
                    : $movement->warehouse->name,
                'quantity' => $delta,
                'unit_cost' => (float) $movement->unit_cost,
                'reason' => $movement->reason,
                'running_quantity' => $runningQuantity,
            ];
        })->values();
    }

    private static function signedDelta(StockMovement $movement, int $warehouseId): float
    {
        $quantity = (float) $movement->quantity;

        return match ($movement->type) {
            'receipt' => $quantity,
            'issue' => -$quantity,
            'adjustment' => $quantity,
            'transfer' => $movement->to_warehouse_id === $warehouseId ? $quantity : -$quantity,
            default => 0.0,
        };
    }
}
