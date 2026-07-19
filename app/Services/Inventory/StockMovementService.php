<?php

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    private const QTY_SCALE = 3;

    private const COST_SCALE = 4;

    private const VALUE_SCALE = 6;

    public function receipt(Item $item, Warehouse $warehouse, string $quantity, string $unitCost, string $movementDate, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $unitCost, $movementDate, $createdBy) {
            $level = $this->lockedLevel($item, $warehouse);

            $oldValue = bcmul($level->quantity_on_hand, $level->average_cost, self::VALUE_SCALE);
            $newValue = bcmul($quantity, $unitCost, self::VALUE_SCALE);
            $newQty = bcadd($level->quantity_on_hand, $quantity, self::QTY_SCALE);
            $newAvgCost = bccomp($newQty, '0', self::QTY_SCALE) > 0
                ? self::bcDivRoundHalfUp(bcadd($oldValue, $newValue, self::VALUE_SCALE), $newQty, self::COST_SCALE)
                : '0.0000';

            $level->update(['quantity_on_hand' => $newQty, 'average_cost' => $newAvgCost]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'receipt',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function issue(Item $item, Warehouse $warehouse, string $quantity, string $movementDate, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $movementDate, $createdBy) {
            $level = $this->lockedLevel($item, $warehouse);

            if (bccomp($level->quantity_on_hand, $quantity, self::QTY_SCALE) < 0) {
                throw new InsufficientStockException(
                    "Cannot issue {$quantity} of item #{$item->id} from warehouse #{$warehouse->id}: only {$level->quantity_on_hand} on hand."
                );
            }

            $unitCost = $level->average_cost;
            $newQty = bcsub($level->quantity_on_hand, $quantity, self::QTY_SCALE);
            $level->update(['quantity_on_hand' => $newQty]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'issue',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function transfer(Item $item, Warehouse $fromWarehouse, Warehouse $toWarehouse, string $quantity, string $movementDate, int $createdBy): StockMovement
    {
        if ($fromWarehouse->is($toWarehouse)) {
            throw new \InvalidArgumentException('Cannot transfer stock to the same warehouse.');
        }

        return DB::transaction(function () use ($item, $fromWarehouse, $toWarehouse, $quantity, $movementDate, $createdBy) {
            // Lock both warehouse levels in a fixed order (ascending warehouse
            // id) regardless of transfer direction, so two concurrent
            // transfers between the same pair of warehouses can never
            // deadlock on each other's locks.
            [$firstWarehouse, $secondWarehouse] = $fromWarehouse->id <= $toWarehouse->id
                ? [$fromWarehouse, $toWarehouse]
                : [$toWarehouse, $fromWarehouse];

            $firstLevel = $this->lockedLevel($item, $firstWarehouse);
            $secondLevel = $this->lockedLevel($item, $secondWarehouse);

            $fromLevel = $firstWarehouse->is($fromWarehouse) ? $firstLevel : $secondLevel;
            $toLevel = $firstWarehouse->is($fromWarehouse) ? $secondLevel : $firstLevel;

            if (bccomp($fromLevel->quantity_on_hand, $quantity, self::QTY_SCALE) < 0) {
                throw new InsufficientStockException(
                    "Cannot transfer {$quantity} of item #{$item->id} from warehouse #{$fromWarehouse->id}: only {$fromLevel->quantity_on_hand} on hand."
                );
            }

            $costAtSource = $fromLevel->average_cost;
            $fromLevel->update(['quantity_on_hand' => bcsub($fromLevel->quantity_on_hand, $quantity, self::QTY_SCALE)]);

            $oldValue = bcmul($toLevel->quantity_on_hand, $toLevel->average_cost, self::VALUE_SCALE);
            $incomingValue = bcmul($quantity, $costAtSource, self::VALUE_SCALE);
            $newToQty = bcadd($toLevel->quantity_on_hand, $quantity, self::QTY_SCALE);
            $newToAvgCost = bccomp($newToQty, '0', self::QTY_SCALE) > 0
                ? self::bcDivRoundHalfUp(bcadd($oldValue, $incomingValue, self::VALUE_SCALE), $newToQty, self::COST_SCALE)
                : '0.0000';
            $toLevel->update(['quantity_on_hand' => $newToQty, 'average_cost' => $newToAvgCost]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'type' => 'transfer',
                'quantity' => $quantity,
                'unit_cost' => $costAtSource,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * NOTE: lockForUpdate() here only holds a real lock because every public
     * method wraps its call to this helper in DB::transaction() — same
     * caveat as JournalEntry's entry_number sequencing in Phase 1.
     */
    private function lockedLevel(Item $item, Warehouse $warehouse): StockLevel
    {
        StockLevel::firstOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $warehouse->id],
            ['quantity_on_hand' => '0', 'average_cost' => '0']
        );

        return StockLevel::where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Divide two bcmath strings and round the result to $scale decimal
     * places using round-half-up, instead of bcdiv()'s native truncation.
     *
     * PHP 8.3's bcmath has no bcround(), so we divide at a higher "guard"
     * precision, nudge the value by half a unit at the target scale, and
     * let a final truncating bcadd() collapse it to $scale. This avoids
     * the one-directional downward drift that truncate-then-reconstruct
     * causes when a weighted-average cost is recalculated repeatedly
     * (e.g. many receipts at the same unit cost).
     */
    private static function bcDivRoundHalfUp(string $dividend, string $divisor, int $scale): string
    {
        $guardScale = $scale + 10;
        $quotient = bcdiv($dividend, $divisor, $guardScale);

        $half = '0.' . str_repeat('0', $scale) . '5';

        if (bccomp($quotient, '0', $guardScale) < 0) {
            return bcsub($quotient, $half, $scale);
        }

        return bcadd($quotient, $half, $scale);
    }
}
