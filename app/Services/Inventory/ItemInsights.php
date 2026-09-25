<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoiceLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Податоците за десниот дел од екранот за артикли: продажба по ден,
 * трансакции и залиха. Само за приказ — ништо од тука не се книжи, па
 * збировите се обични децимални броеви, не bcmath.
 */
class ItemInsights
{
    public const PERIODS = ['this_month', 'last_month', 'this_year'];

    public const TRANSACTION_FILTERS = ['all', 'sales', 'purchases', 'stock'];

    /**
     * Продажба на артиклот (потврдени фактури) по ден, во денари, без ДДВ.
     *
     * @return array{days: array<int, array{date: string, amount: float}>, total: float, quantity: float, from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function salesSummary(Item $item, string $period, ?CarbonImmutable $today = null): array
    {
        [$from, $to] = self::periodBounds($period, $today ?? CarbonImmutable::today());

        $lines = SalesInvoiceLine::query()
            ->where('item_id', $item->id)
            ->whereHas('salesInvoice', fn ($q) => $q
                ->where('status', 'confirmed')
                ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]))
            ->with('salesInvoice:id,invoice_date,currency,exchange_rate')
            ->get();

        $perDay = [];
        $quantity = 0.0;

        foreach ($lines as $line) {
            $key = $line->salesInvoice->invoice_date->toDateString();
            $rate = $line->salesInvoice->isForeignCurrency() ? (float) $line->salesInvoice->exchange_rate : 1.0;
            $perDay[$key] = ($perDay[$key] ?? 0.0) + (float) $line->lineTotal() * $rate;
            $quantity += (float) $line->quantity;
        }

        // По месец (за годишен преглед) наместо по ден, инаку има 365 ситни столбови.
        $grouped = $period === 'this_year'
            ? collect($perDay)->groupBy(fn ($v, $date) => substr($date, 0, 7))->map(fn ($g) => $g->sum())
            : collect($perDay);

        $days = $grouped->sortKeys()
            ->map(fn ($amount, $date) => ['date' => $date, 'amount' => round($amount, 2)])
            ->values()
            ->all();

        return [
            'days' => $days,
            'total' => round(array_sum($perDay), 2),
            'quantity' => $quantity,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function periodBounds(string $period, CarbonImmutable $today): array
    {
        return match ($period) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
    }

    /**
     * Залиха по магацин. Празно за услуга — таа не се води по залиха.
     *
     * @return Collection<int, array{warehouse: string, quantity: float, average_cost: float}>
     */
    public static function stockByWarehouse(Item $item): Collection
    {
        if ($item->isService()) {
            return collect();
        }

        return StockLevel::query()
            ->where('item_id', $item->id)
            ->with('warehouse:id,name')
            ->get()
            ->map(fn (StockLevel $level) => [
                'warehouse' => (string) $level->warehouse?->name,
                'quantity' => (float) $level->quantity_on_hand,
                'average_cost' => (float) $level->average_cost,
            ])
            ->sortBy('warehouse')
            ->values();
    }

    /**
     * Сите трансакции на артиклот, најновите први: редови од излезни и влезни
     * фактури и движења на залиха. Движењата што ги направила фактура не се
     * повторуваат — таму се веќе преку фактурата.
     *
     * @return Collection<int, array{date: string, kind: string, label: string, document: string, partner: string, quantity: float, amount: float, status: string, kind_key: string, route: ?array}>
     */
    public static function transactions(Item $item, string $filter, int $limit = 200): Collection
    {
        $rows = collect();

        if (in_array($filter, ['all', 'sales'], true)) {
            $rows = $rows->merge(
                SalesInvoiceLine::query()
                    ->where('item_id', $item->id)
                    ->with('salesInvoice.partner:id,name')
                    ->get()
                    ->map(fn (SalesInvoiceLine $line) => [
                        'date' => $line->salesInvoice->invoice_date->toDateString(),
                        'kind_key' => 'sales',
                        'kind' => 'Излезна фактура',
                        'document' => $line->salesInvoice->formattedNumber() ?? 'Нацрт',
                        'partner' => (string) $line->salesInvoice->partner?->name,
                        'quantity' => (float) $line->quantity,
                        'amount' => (float) $line->lineTotal(),
                        'currency' => $line->salesInvoice->currency,
                        'status' => $line->salesInvoice->status,
                        'route' => ['sales-invoices.show', [$line->salesInvoice->company_id, $line->salesInvoice->id]],
                    ])
            );
        }

        if (in_array($filter, ['all', 'purchases'], true)) {
            $rows = $rows->merge(
                PurchaseInvoiceLine::query()
                    ->where('item_id', $item->id)
                    ->with('purchaseInvoice.partner:id,name')
                    ->get()
                    ->map(fn (PurchaseInvoiceLine $line) => [
                        'date' => $line->purchaseInvoice->invoice_date->toDateString(),
                        'kind_key' => 'purchases',
                        'kind' => 'Влезна фактура',
                        'document' => (string) $line->purchaseInvoice->supplier_invoice_number,
                        'partner' => (string) $line->purchaseInvoice->partner?->name,
                        'quantity' => (float) $line->quantity,
                        'amount' => (float) $line->lineTotal(),
                        'currency' => 'MKD',
                        'status' => $line->purchaseInvoice->status,
                        'route' => ['purchase-invoices.show', [$line->purchaseInvoice->company_id, $line->purchaseInvoice->id]],
                    ])
            );
        }

        if (in_array($filter, ['all', 'stock'], true)) {
            $invoiceMovementIds = SalesInvoiceLine::where('item_id', $item->id)->whereNotNull('stock_movement_id')->pluck('stock_movement_id')
                ->merge(PurchaseInvoiceLine::where('item_id', $item->id)->whereNotNull('stock_movement_id')->pluck('stock_movement_id'));

            $rows = $rows->merge(
                StockMovement::query()
                    ->where('item_id', $item->id)
                    ->whereNotIn('id', $invoiceMovementIds)
                    ->with('warehouse:id,name')
                    ->get()
                    ->map(fn (StockMovement $movement) => [
                        'date' => $movement->movement_date->toDateString(),
                        'kind_key' => 'stock',
                        'kind' => 'Движење на залиха',
                        'document' => \App\Support\Format::movementType($movement->type),
                        'partner' => (string) $movement->warehouse?->name,
                        'quantity' => (float) $movement->quantity,
                        'amount' => round((float) $movement->quantity * (float) $movement->unit_cost, 2),
                        'currency' => 'MKD',
                        'status' => '',
                        'route' => null,
                    ])
            );
        }

        return $rows->sortByDesc('date')->take($limit)->values();
    }
}
