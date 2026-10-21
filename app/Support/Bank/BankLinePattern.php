<?php

namespace App\Support\Bank;

/**
 * Образец на текстот од ставка на извод: она што останува кога се тргнат
 * броевите, датумите, месеците и редните зборови. „Prov.PP … за трета декада
 * за 07“ и „… за втора декада за 08“ даваат ист образец, па контото внесено за
 * едната се нуди за другата.
 *
 * Текст без смисла (само бројки, „000“) нема образец — null.
 */
final class BankLinePattern
{
    private const MAX = 80;

    /** Месеци и редни зборови во латиница без дијакритици (како што пишува банката). */
    private const MOVING_WORDS = [
        'prva', 'vtora', 'treta', 'cetvrta', 'prvi', 'vtor', 'tret',
        'januari', 'fevruari', 'mart', 'april', 'maj', 'juni', 'juli',
        'avgust', 'septemvri', 'oktomvri', 'noemvri', 'dekemvri',
    ];

    public static function from(?string $description): ?string
    {
        $text = mb_strtolower(trim((string) $description));

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/\p{N}+/u', '#', $text);
        $text = (string) preg_replace('/(?<!\p{L})('.implode('|', self::MOVING_WORDS).')(?!\p{L})/u', '#', $text);
        $text = (string) preg_replace('/[^\p{L}#]+/u', ' ', $text);
        $text = (string) preg_replace('/#(?:\s*#)+/u', '#', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (preg_match_all('/\p{L}/u', $text) < 3) {
            return null;
        }

        return rtrim(mb_substr($text, 0, self::MAX));
    }
}
