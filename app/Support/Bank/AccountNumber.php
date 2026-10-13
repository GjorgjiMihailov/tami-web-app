<?php

namespace App\Support\Bank;

/**
 * Една иста сметка се пишува различно: „300000000012345“, „300-0000000123-45“,
 * или како IBAN „MK07300000000012345“. Банката го праќа првиот облик, а
 * профилот на фирмата често го има вториот или третиот. Споредба по текст би ја
 * сметала за различна сметка — дупликат се пропушта, а туѓа сметка не се
 * препознава.
 */
final class AccountNumber
{
    /** Сметка без цртички и празни места; IBAN МК се сведува на домашниот број од 15 знаци. */
    public static function key(string $value): string
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return preg_match('/^MK\d{2}([0-9A-Z]{15})$/', $key, $m) === 1 ? $m[1] : $key;
    }

    public static function same(string $a, string $b): bool
    {
        $key = self::key($a);

        return $key !== '' && $key === self::key($b);
    }
}
