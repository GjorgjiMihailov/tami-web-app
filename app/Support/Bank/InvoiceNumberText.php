<?php

namespace App\Support\Bank;

/**
 * Дали број на фактура е наведен во текст од извод. Броевите се пишуваат
 * различно („00004-26“, „4/26“, „fra 00004-26“), па се споредуваат како низа
 * од цели единици (броеви без водечки нули и зборови), не како залепен текст:
 * „00004-26“ го наоѓа „fra 00004-26“, но не и „1000042 6“.
 */
final class InvoiceNumberText
{
    /** Најмалку толку знаци (без нули и разделници) за број да се бара во текстот. */
    private const MIN_SIGNIFICANT = 3;

    public static function contains(string $text, string $number): bool
    {
        $needle = self::tokens($number);

        if ($needle === [] || mb_strlen(implode('', $needle)) < self::MIN_SIGNIFICANT) {
            return false;
        }

        $haystack = self::tokens($text);
        $size = count($needle);

        for ($start = 0; $start + $size <= count($haystack); $start++) {
            if (array_slice($haystack, $start, $size) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function tokens(string $value): array
    {
        preg_match_all('/\d+|\p{L}+/u', mb_strtolower($value), $matches);

        return array_map(
            fn (string $token) => ctype_digit($token) ? (ltrim($token, '0') === '' ? '0' : ltrim($token, '0')) : $token,
            $matches[0],
        );
    }
}
