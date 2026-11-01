<?php

namespace App\Support;

/** Валути на девизен извод. За почеток само EUR и USD (одлука 2026-10-28). */
final class BankStatementCurrency
{
    /** @return list<string> */
    public static function all(): array
    {
        return ['EUR', 'USD'];
    }
}
