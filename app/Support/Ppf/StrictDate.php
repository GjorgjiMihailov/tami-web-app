<?php

namespace App\Support\Ppf;

use Carbon\Carbon;

/** Датум гггг-мм-дд што навистина постои; Carbon::createFromFormat фрла исклучок за погрешен облик, а „31.02.“ го префрла во март. */
final class StrictDate
{
    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }
}
