<?php

namespace App\Support;

class Bcmath
{
    /**
     * Round a bcmath string to $scale decimal places using round-half-up,
     * instead of bcmath's native truncation. Same algorithm as Phase 2's
     * StockMovementService::bcDivRoundHalfUp() — kept separate rather than
     * modifying that already-shipped, tested private method, but the
     * rounding behavior is intentionally identical.
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', $scale + 10) < 0) {
            return bcsub($value, $half, $scale);
        }

        return bcadd($value, $half, $scale);
    }
}
