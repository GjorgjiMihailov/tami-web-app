<?php

namespace App\Support\Bank\Mt940;

use App\Support\Bank\LineDirection;

/**
 * Чита еден MT940 извод од банка. Чиста класа: ништо не запишува.
 *
 * Проверено на вистински изводи од Комерцијална банка: два облика на :61:,
 * износи со запирка (без децимали за цел износ), :86: со 4 линии
 * (/ORDP|BENM/сметка, име, референци, опис). Сè што не личи на тоа не се
 * погодува — или цел фајл се одбива (пари што не се разбираат), или :86:
 * оди во описот а другата страна останува непозната.
 */
final class Mt940Parser
{
    private const INTERIM_TAGS = ['60M', '62M', '64', '65'];

    private const MAX_TEXT = 255;

    /** Тргнува BOM и ги претвора не-UTF-8 бајти (Windows-1252/ISO-8859-1) во UTF-8. */
    public static function decode(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    public static function parse(string $text): Mt940Statement
    {
        $text = str_replace("\r", '', self::decode($text));
        $fields = self::fields(self::body($text));
        $tags = array_column($fields, 0);

        if (array_intersect($tags, self::INTERIM_TAGS) !== []) {
            throw new Mt940Exception('Изводот има меѓусостојби (:60M:, :62M:) или состојби :64:/:65: — тоа не е поддржано.');
        }

        foreach (['20', '25', '28C', '60F', '62F'] as $tag) {
            if (count(array_keys($tags, $tag, true)) > 1) {
                throw new Mt940Exception('Фајлот содржи повеќе од еден извод — увезете ги одделно.');
            }
        }

        foreach (['25', '28C', '60F', '62F'] as $tag) {
            if (! in_array($tag, $tags, true)) {
                throw new Mt940Exception("Во фајлот недостасува поле :{$tag}:.");
            }
        }

        $account = self::account(self::value($fields, '25'));
        $number = self::number(self::value($fields, '28C'));
        [$date, $openingCurrency, $opening] = self::balance(self::value($fields, '60F'));
        [, $closingCurrency, $closing] = self::balance(self::value($fields, '62F'));

        foreach (array_unique([$openingCurrency, $closingCurrency]) as $currency) {
            if ($currency !== 'MKD') {
                throw new Mt940Exception("Валутата е {$currency}. Се увезуваат само денарски изводи (MKD).");
            }
        }

        $bic = preg_match('/\{1:F01([A-Z0-9]{8})/', $text, $m) === 1 ? $m[1] : null;

        return new Mt940Statement($account, $number, $date, $opening, $closing, self::lines($fields), $bic);
    }

    private static function body(string $text): string
    {
        $start = strpos($text, '{4:');

        if ($start === false) {
            return $text;
        }

        $body = substr($text, $start + 3);
        $end = strrpos($body, '-}');

        return $end === false ? $body : substr($body, 0, $end);
    }

    /**
     * Поле почнува со „:20:“ / „:28C:“; редовите што следат му припаѓаат на него.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fields(string $body): array
    {
        $fields = [];

        foreach (explode("\n", $body) as $row) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $row, $m) === 1) {
                $fields[] = [$m[1], $m[2]];
            } elseif ($fields !== [] && trim($row) !== '' && trim($row) !== '-') {
                $fields[count($fields) - 1][1] .= "\n".$row;
            }
        }

        return $fields;
    }

    /** Првиот ред на првото поле со таа ознака. */
    private static function value(array $fields, string $tag): string
    {
        foreach ($fields as [$name, $value]) {
            if ($name === $tag) {
                return trim(explode("\n", $value)[0]);
            }
        }

        return '';
    }

    private static function account(string $raw): string
    {
        $account = str_contains($raw, '/') ? substr($raw, (int) strrpos($raw, '/') + 1) : $raw;

        if (preg_match('/^[A-Za-z0-9]{1,34}$/D', $account) !== 1) {
            throw new Mt940Exception("Неважечка сметка (:25:) „{$raw}“.");
        }

        return $account;
    }

    private static function number(string $raw): int
    {
        if (preg_match('/^(\d{1,6})(?:\/\d+)?$/D', $raw, $m) !== 1 || (int) $m[1] < 1) {
            throw new Mt940Exception("Неважечки број на извод (:28C:) „{$raw}“.");
        }

        return (int) $m[1];
    }

    /** @return array{0: string, 1: string, 2: string} датум, валута, износ со знак */
    private static function balance(string $raw): array
    {
        if (preg_match('/^([CD])(\d{6})([A-Z]{3})(\d+(?:,\d{0,2})?)$/D', $raw, $m) !== 1) {
            throw new Mt940Exception("Неважечка состојба „{$raw}“.");
        }

        $amount = self::amount($m[4]);

        if ($m[1] === 'D' && bccomp($amount, '0', 2) !== 0) {
            $amount = bcmul($amount, '-1', 2);
        }

        return [self::date($m[2]), $m[3], $amount];
    }

    /** „17,“ → 17.00, „17,5“ → 17.50. Нема разделник на илјади — се одбива. */
    private static function amount(string $raw): string
    {
        if (preg_match('/^(\d+)(?:,(\d{0,2}))?$/D', $raw, $m) !== 1) {
            throw new Mt940Exception("Неважечки износ „{$raw}“.");
        }

        return $m[1].'.'.str_pad($m[2] ?? '', 2, '0');
    }

    private static function date(string $yymmdd): string
    {
        $year = 2000 + (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        if (! checkdate($month, $day, $year)) {
            throw new Mt940Exception("Неважечки датум „{$yymmdd}“.");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Секое :61: го добива следното :86:. :86: пред првата ставка или по
     * затворањето (:62F:) е за целиот извод и не се користи.
     *
     * @param  list<array{0: string, 1: string}>  $fields
     * @return list<Mt940Line>
     */
    private static function lines(array $fields): array
    {
        $pairs = [];
        $closed = false;

        foreach ($fields as [$tag, $value]) {
            if ($tag === '62F') {
                $closed = true;
            } elseif ($tag === '61' && ! $closed) {
                $pairs[] = ['61' => $value, '86' => null];
            } elseif ($tag === '86' && ! $closed && $pairs !== []) {
                $last = count($pairs) - 1;
                $pairs[$last]['86'] = ($pairs[$last]['86'] === null ? '' : $pairs[$last]['86']."\n").$value;
            }
        }

        return array_map(fn (array $pair) => self::line($pair['61'], $pair['86']), $pairs);
    }

    private static function line(string $raw61, ?string $raw86): Mt940Line
    {
        // Вториот ред на :61: е дополнителен опис на банката — не се користи.
        $first = trim(explode("\n", $raw61)[0]);

        if (preg_match('/^(\d{6})(?:\d{4})?(RC|RD|C|D)([A-Z])?(\d+(?:,\d{0,2})?)N([A-Z0-9]{3})(.*)$/D', $first, $m) !== 1) {
            throw new Mt940Exception("Неважечка ставка :61: „{$first}“.");
        }

        if ($m[2] === 'RC' || $m[2] === 'RD') {
            throw new Mt940Exception('Сторно ставка (RC/RD) не е поддржана.');
        }

        [$customer, $bank] = array_pad(explode('//', trim($m[6]), 2), 2, null);
        $bankReference = self::clean($bank ?? $customer);
        $bankReference = $bankReference === 'NONREF' ? null : $bankReference;

        [$account, $name, $references, $description] = self::details($raw86);

        return new Mt940Line(
            date: self::date($m[1]),
            direction: $m[2] === 'C' ? LineDirection::IN : LineDirection::OUT,
            amount: self::amount($m[4]),
            counterpartyAccount: $account,
            counterpartyName: $name,
            referenceNumber: $references,
            description: $description,
            bankReference: $bankReference,
        );
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: string} */
    private static function details(?string $raw86): array
    {
        if ($raw86 === null) {
            return [null, null, null, ''];
        }

        $rows = explode("\n", $raw86);

        if (preg_match('/^\/(ORDP|BENM)\/([A-Za-z0-9]{1,34})\s*$/', $rows[0], $m) !== 1) {
            return [null, null, null, self::cut((string) self::clean(implode(' ', $rows)))];
        }

        $references = self::clean(self::unslash($rows[2] ?? ''));

        if ($references !== null && preg_match('/^[0\/\s]*$/', $references) === 1) {
            $references = null;
        }

        $description = (string) self::clean(implode(' ', array_map(self::unslash(...), array_slice($rows, 3))));

        return [
            $m[2],
            self::cutOrNull(self::clean(self::unslash($rows[1] ?? ''))),
            $references,
            self::cut($description),
        ];
    }

    private static function unslash(string $row): string
    {
        return str_starts_with($row, '/') ? substr($row, 1) : $row;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private static function cut(string $value): string
    {
        return mb_substr($value, 0, self::MAX_TEXT);
    }

    private static function cutOrNull(?string $value): ?string
    {
        return $value === null ? null : self::cut($value);
    }
}
