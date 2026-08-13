<?php

namespace App\Support;

class Embg
{
    /**
     * ДДММГГГРРБББК — 13 digits, the last being a modulo-11 check digit over
     * the first 12 with the weights 7,6,5,4,3,2 repeated twice.
     *
     * A remainder of 1 would require a check digit of 10, which cannot be
     * written in one position, so such numbers were never issued.
     */
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
