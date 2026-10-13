<?php

namespace App\Support\Bank\Mt940;

/** BIC → име на банка. Само она што е видено на вистински фајл. */
final class BankDirectory
{
    private const NAMES = [
        'KOBSMK2X' => 'Комерцијална банка АД Скопје',
    ];

    public static function name(?string $bic): ?string
    {
        return $bic === null ? null : (self::NAMES[$bic] ?? null);
    }
}
