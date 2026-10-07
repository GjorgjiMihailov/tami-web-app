<?php

namespace App\Services\Inventory;

use App\Models\PurchaseInvoiceLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Го враќа „увозниот дел“ на постојната залиха од историјата на движењата,
 * со истите формули како StockMovementService. Пушта по артикл; ако
 * пресметаната количина не се совпаѓа со салдото, артиклот се прескокнува.
 */
class StockImportShareBackfill
{
    /** @return array{items: int, skipped: int} */
    public function run(): array
    {
        $importMovementIds = PurchaseInvoiceLine::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->where('purchase_invoices.is_import', true)
            ->whereNotNull('purchase_invoice_lines.stock_movement_id')
            ->pluck('purchase_invoice_lines.stock_movement_id')
            ->flip();

        $done = 0;
        $skipped = 0;

        foreach (StockMovement::query()->distinct()->pluck('item_id') as $itemId) {
            $this->replay((int) $itemId, $importMovementIds) ? $done++ : $skipped++;
        }

        return ['items' => $done, 'skipped' => $skipped];
    }

    private function replay(int $itemId, $importMovementIds): bool
    {
        $state = [];
        $movementImport = [];

        foreach (StockMovement::where('item_id', $itemId)->orderBy('movement_date')->orderBy('id')->get() as $m) {
            $qty = (string) $m->quantity;
            $cost = (string) $m->unit_cost;
            $from = $m->warehouse_id;
            $state[$from] ??= ['qty' => '0.000', 'avg' => '0.0000', 'imp' => '0.000000'];

            switch ($m->type) {
                case 'receipt':
                    $value = bcmul($qty, $cost, 6);
                    $part = isset($importMovementIds[$m->id]) ? $value : '0.000000';
                    $old = bcmul($state[$from]['qty'], $state[$from]['avg'], 6);
                    $state[$from]['qty'] = bcadd($state[$from]['qty'], $qty, 3);
                    $state[$from]['avg'] = bccomp($state[$from]['qty'], '0', 3) > 0
                        ? StockMovementService::bcDivRoundHalfUp(bcadd($old, $value, 6), $state[$from]['qty'], 4) : '0.0000';
                    $state[$from]['imp'] = bcadd($state[$from]['imp'], $part, 6);
                    $movementImport[$m->id] = $part;
                    break;

                case 'issue':
                    $part = $this->take($state[$from], bcmul($qty, $state[$from]['avg'], 7));
                    $state[$from]['qty'] = bcsub($state[$from]['qty'], $qty, 3);
                    $state[$from]['imp'] = bccomp($state[$from]['qty'], '0', 3) > 0 ? bcsub($state[$from]['imp'], $part, 6) : '0.000000';
                    $movementImport[$m->id] = $part;
                    break;

                case 'transfer':
                    $to = $m->to_warehouse_id;
                    $state[$to] ??= ['qty' => '0.000', 'avg' => '0.0000', 'imp' => '0.000000'];
                    $costAtSource = $state[$from]['avg'];
                    $part = $this->take($state[$from], bcmul($qty, $costAtSource, 7));
                    $state[$from]['qty'] = bcsub($state[$from]['qty'], $qty, 3);
                    $state[$from]['imp'] = bccomp($state[$from]['qty'], '0', 3) > 0 ? bcsub($state[$from]['imp'], $part, 6) : '0.000000';
                    $old = bcmul($state[$to]['qty'], $state[$to]['avg'], 6);
                    $state[$to]['qty'] = bcadd($state[$to]['qty'], $qty, 3);
                    $state[$to]['avg'] = bccomp($state[$to]['qty'], '0', 3) > 0
                        ? StockMovementService::bcDivRoundHalfUp(bcadd($old, bcmul($qty, $costAtSource, 6), 6), $state[$to]['qty'], 4) : '0.0000';
                    $state[$to]['imp'] = bcadd($state[$to]['imp'], $part, 6);
                    $movementImport[$m->id] = $part;
                    break;

                case 'adjustment':
                    $oldQty = $state[$from]['qty'];
                    $newQty = bcadd($oldQty, $qty, 3);
                    $newImp = bccomp($oldQty, '0', 3) > 0 && bccomp($newQty, '0', 3) > 0
                        ? StockMovementService::bcDivRoundHalfUp(bcmul($state[$from]['imp'], $newQty, 9), $oldQty, 6) : '0.000000';
                    $movementImport[$m->id] = ltrim(bcsub($state[$from]['imp'], $newImp, 6), '-');
                    $state[$from]['qty'] = $newQty;
                    $state[$from]['imp'] = $newImp;
                    break;
            }
        }

        $levels = StockLevel::where('item_id', $itemId)->get()->keyBy('warehouse_id');

        foreach ($state as $warehouseId => $s) {
            $level = $levels[$warehouseId] ?? null;

            if ($level === null || bccomp((string) $level->quantity_on_hand, $s['qty'], 3) !== 0) {
                return false;
            }
        }

        DB::transaction(function () use ($state, $levels, $movementImport) {
            foreach ($movementImport as $id => $part) {
                StockMovement::whereKey($id)->update(['import_value' => $part]);
            }

            foreach ($state as $warehouseId => $s) {
                $levels[$warehouseId]->update(['import_value' => $s['imp']]);
            }
        });

        return true;
    }

    /** Пропорционален дел од $cost што е од увоз; никогаш повеќе од увозот на залихата. */
    private function take(array $state, string $cost): string
    {
        $value = bcmul($state['qty'], $state['avg'], 7);
        $part = bccomp($value, '0', 7) > 0
            ? StockMovementService::bcDivRoundHalfUp(bcmul($cost, $state['imp'], 13), $value, 6)
            : '0.000000';

        $part = bccomp($part, $state['imp'], 6) > 0 ? $state['imp'] : $part;

        return bccomp($part, $cost, 6) > 0 ? bcadd($cost, '0', 6) : bcadd($part, '0', 6);
    }
}
