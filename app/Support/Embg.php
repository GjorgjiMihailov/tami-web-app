<?php

namespace App\Support;

/**
 * ЕМБГ — the Macedonian unique citizen number, ДДММГГГРРБББК, 13 digits.
 *
 * The check digit is the JMBG/ЕМБГ modulo-11 scheme inherited from the former
 * Yugoslav standard. The spec for this phase
 * (docs/superpowers/specs/2026-08-13-payroll-5a-employees-design.md, "Отворени
 * прашања" 1) required it to be confirmed from a source rather than written
 * from memory. It was confirmed during planning and then independently
 * re-derived by a reviewer, so it is recorded here in full and does not need
 * deriving again:
 *
 *   1. Multiply the first twelve digits by the weights 7,6,5,4,3,2 repeated
 *      twice, and sum.
 *   2. m = sum mod 11.
 *   3. The check digit is 0 when m is 0, otherwise 11 − m.
 *   4. m = 1 would demand a check digit of 10, which does not fit one
 *      position, so such numbers were never issued — treat them as invalid.
 *
 * Worked example, 3101980455019 (the valid number used across the tests):
 *
 *   digits  3  1  0  1  9  8  0  4  5  5  0  1
 *   weights 7  6  5  4  3  2  7  6  5  4  3  2
 *   terms  21  6  0  4 27 16  0 24 25 20  0  2  → sum 145
 *   145 mod 11 = 2  →  check digit 11 − 2 = 9   → matches the 13th digit
 */
class Embg
{
    private const WEIGHTS = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $embg): bool
    {
        if (preg_match('/^\d{13}$/', $embg) !== 1) {
            return false;
        }

        $day = (int) substr($embg, 0, 2);
        $month = (int) substr($embg, 2, 2);

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return false;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $position => $weight) {
            $sum += ((int) $embg[$position]) * $weight;
        }

        $remainder = $sum % 11;

        if ($remainder === 1) {
            return false;
        }

        $check = $remainder === 0 ? 0 : 11 - $remainder;

        return $check === (int) $embg[12];
    }
}
