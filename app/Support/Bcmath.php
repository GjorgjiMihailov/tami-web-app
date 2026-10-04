<?php

namespace App\Support;

class Bcmath
{
    /**
     * Дали стрингот е чист децимален број што bcmath го прима без да фрли.
     * `is_numeric` не е доволно: прима „ 12“, „12 “ и „1e3“, а bcadd/bcmul
     * на нив фрлаат ValueError — што за скенирани износи значи 500 наместо ред со нула.
     */
    public static function isPlainNumber(?string $value): bool
    {
        // Флагот D: без него `$` би го примил и крајниот нов ред („12\n“).
        return $value !== null && preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1;
    }

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
