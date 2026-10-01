<?php

namespace App\Services\Inventory;

use App\Support\Bcmath;

/**
 * Distributes total import costs (freight, forwarder, customs duty — no
 * VAT) across a purchase invoice's stock lines, proportional to each
 * line's net value. Pure and stateless: works equally on an in-memory
 * draft (Livewire form preview) and on persisted lines (confirm-time
 * stock receipt) — both callers build the same small array shape and
 * never store a separately-computed landed cost anywhere.
 */
class LandedCostAllocator
{
    private const NET_SCALE = 2;

    private const COST_SCALE = 4;

    /**
     * @param  array<string, array{net: string, quantity: string}>  $stockLines
     * @return array<string, string>
     */
    public function allocate(array $stockLines, string $totalImportCosts): array
    {
        if ($stockLines === []) {
            return [];
        }

        $totalNet = array_reduce(
            $stockLines,
            fn (string $carry, array $line) => bcadd($carry, $line['net'], self::NET_SCALE),
            '0.00'
        );

        if (bccomp($totalImportCosts, '0', self::NET_SCALE) <= 0 || bccomp($totalNet, '0', self::NET_SCALE) <= 0) {
            return array_map(
                fn (array $line) => $this->unitCost($line['net'], $line['quantity']),
                $stockLines
            );
        }

        $keys = array_keys($stockLines);
        $lastKey = $keys[count($keys) - 1];
        $allocated = '0.00';
        $result = [];

        foreach ($stockLines as $key => $line) {
            if ($key === $lastKey) {
                $share = bcsub($totalImportCosts, $allocated, self::NET_SCALE);
            } else {
                $share = Bcmath::roundHalfUp(
                    bcdiv(bcmul($line['net'], $totalImportCosts, self::NET_SCALE + 10), $totalNet, self::NET_SCALE + 10),
                    self::NET_SCALE
                );
                $allocated = bcadd($allocated, $share, self::NET_SCALE);
            }

            $landedValue = bcadd($line['net'], $share, self::NET_SCALE);
            $result[$key] = $this->unitCost($landedValue, $line['quantity']);
        }

        return $result;
    }

    private function unitCost(string $value, string $quantity): string
    {
        if (bccomp($quantity, '0', 3) <= 0) {
            return '0.0000';
        }

        return Bcmath::roundHalfUp(bcdiv($value, $quantity, self::COST_SCALE + 10), self::COST_SCALE);
    }
}
