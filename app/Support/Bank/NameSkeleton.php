<?php

namespace App\Support\Bank;

/**
 * Име на партнер и име од банка (латиница без ќ/ѓ/љ/њ/џ) сведени на ист облик
 * за споредба: „Ѓорѓи Михаилов ДООЕЛ Скопје“ и „GORGI MIHAILOV“ даваат иста
 * низа збор-коски. Грубо по дизајн — користи се само за предлог „проверете“.
 */
final class NameSkeleton
{
    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ѓ' => 'g', 'е' => 'e', 'ж' => 'z',
        'з' => 'z', 'ѕ' => 'z', 'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'l', 'м' => 'm',
        'н' => 'n', 'њ' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ќ' => 'k',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'z', 'ш' => 's',
    ];

    /** Правна форма и место — не го разликуваат лицето. */
    private const NOISE = ['dooel', 'doo', 'ad', 'dd', 'sk', 'skopje', 'ul', 'br', 'kompanija', 'trgovsko', 'drustvo'];

    /** @return list<string> сортирани единствени зборови, без шум */
    public static function tokens(?string $name): array
    {
        $text = strtr(mb_strtolower((string) $name), self::CYRILLIC);
        // Латинските парови што банката ги пишува за ѓ/ќ/љ/њ/џ/ѕ/ж.
        $text = (string) preg_replace(['/gj/', '/kj/', '/lj/', '/nj/', '/dz/', '/zh/'], ['g', 'k', 'l', 'n', 'z', 'z'], $text);
        preg_match_all('/\p{L}{2,}/u', $text, $matches);

        $tokens = array_values(array_unique(array_diff($matches[0], self::NOISE)));
        sort($tokens);

        return $tokens;
    }

    /** Исти збор-коски и барем една од нив долга 4+ знаци (против случајни совпаѓања). */
    public static function same(?string $a, ?string $b): bool
    {
        $left = self::tokens($a);
        $right = self::tokens($b);

        if ($left === [] || $left !== $right) {
            return false;
        }

        return collect($left)->contains(fn (string $token) => mb_strlen($token) >= 4);
    }
}
