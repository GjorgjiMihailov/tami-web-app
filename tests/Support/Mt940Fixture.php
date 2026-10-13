<?php

namespace Tests\Support;

use Carbon\Carbon;

/**
 * Синтетички MT940 во обликот на Комерцијална банка. Сметките, имињата и
 * броевите се измислени — вистинските фајлови не влегуваат во репото.
 */
final class Mt940Fixture
{
    public const ACCOUNT = '100000000000001';

    /**
     * Опции: account, number, date (Y-m-d), opening и closing (со знак, „x.yy“),
     * currency, bic, envelope (false = без {1:}{2:}{4:}), eol, lines.
     * Ставка: dir (in|out), amount, date, party, name, refs, desc, bankref,
     * no86 (true = без :86:), raw86 (слободен :86:).
     *
     * @param  array<string, mixed>  $o
     */
    public static function statement(array $o = []): string
    {
        $account = $o['account'] ?? self::ACCOUNT;
        $number = $o['number'] ?? 1;
        $date = $o['date'] ?? '2026-02-02';
        $currency = $o['currency'] ?? 'MKD';
        $lines = $o['lines'] ?? [];
        $opening = $o['opening'] ?? '0.00';
        $closing = $o['closing'] ?? self::closingFor($opening, $lines);
        $ymd = Carbon::parse($date)->format('ymd');
        $eol = $o['eol'] ?? "\n";

        $rows = [
            ':20:STM'.$number.$ymd,
            ':25:'.$account,
            ':28C:'.$number.'/1',
            ':60F:'.self::balance($opening, $ymd, $currency),
        ];

        foreach ($lines as $line) {
            $lineYmd = Carbon::parse($line['date'] ?? $date)->format('ymd');
            $credit = ($line['dir'] ?? 'in') === 'in';
            $bankRef = $line['bankref'] ?? '1000001';

            $rows[] = ':61:'.$lineYmd.substr($lineYmd, 2, 4).($credit ? 'CD' : 'DD').self::money($line['amount'])
                .($credit ? 'NMSCNONREF//'.$bankRef : 'NMSC'.$bankRef);

            if (isset($line['raw86'])) {
                $rows[] = ':86:'.$line['raw86'];
            } elseif (! ($line['no86'] ?? false)) {
                $rows[] = ':86:/'.($credit ? 'ORDP' : 'BENM').'/'.($line['party'] ?? '100000000000002');
                $rows[] = '/'.($line['name'] ?? 'TEST PARTNER DOOEL SKOPJE');
                $rows[] = '/'.($line['refs'] ?? '000//000');
                $rows[] = '/'.($line['desc'] ?? 'Test opis');
            }
        }

        $rows[] = ':62F:'.self::balance($closing, $ymd, $currency);
        $body = implode($eol, $rows);

        if (($o['envelope'] ?? true) === false) {
            return $body;
        }

        $bic = $o['bic'] ?? 'TESTMK2X';

        return '{1:F01'.$bic.'AXXX0000000000}{2:I940'.$bic.'XXXXXN}{4:'.$eol.$body.$eol.'-}';
    }

    /** @param  list<array<string, mixed>>  $lines */
    private static function closingFor(string $opening, array $lines): string
    {
        $closing = $opening;

        foreach ($lines as $line) {
            $amount = bcadd($line['amount'], '0', 2);
            $closing = ($line['dir'] ?? 'in') === 'in' ? bcadd($closing, $amount, 2) : bcsub($closing, $amount, 2);
        }

        return $closing;
    }

    /** Како банката: „17,“ за цел износ, „17,50“ со децимали. */
    private static function money(string $value): string
    {
        [$whole, $cents] = explode('.', ltrim(bcadd($value, '0', 2), '-'));

        return $whole.','.($cents === '00' ? '' : $cents);
    }

    private static function balance(string $signed, string $ymd, string $currency): string
    {
        return (bccomp($signed, '0', 2) < 0 ? 'D' : 'C').$ymd.$currency.self::money($signed);
    }
}
