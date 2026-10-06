<?php

namespace App\Support\Posting;

/** Матрици: табели што одредуваат конто од вид на ставка и/или даночна група. */
final class PostingMatrix
{
    /** Приход: конто по вид (стока/услуга) И даночна група. */
    public const REVENUE = 'revenue';

    /** Излезен ДДВ: конто само по даночна група. */
    public const OUTPUT_VAT = 'output_vat';

    /** Влезен ДДВ (одбивлив): конто само по даночна група. */
    public const INPUT_VAT = 'input_vat';

    /** Дали матрицата се чита по вид и група (true) или само по група (false). */
    public static function byKind(string $key): bool
    {
        return $key === self::REVENUE;
    }
}
