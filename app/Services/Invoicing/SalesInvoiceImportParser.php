<?php

namespace App\Services\Invoicing;

use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Support\Bcmath;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Ги претвора редовите од Excel-шаблонот за масовен внес на излезни фактури во
 * проверени редови. Ништо не запишува — само чита и проверува.
 *
 * Колони по позиција: 0 број, 1 датум, 2 кооперант, 3 ЕДБ, 4 вкупно од хартија.
 */
class SalesInvoiceImportParser
{
    public const MAX_ROWS = 500;

    /** @return array<int, array<string, mixed>> */
    public function parse(array $rows, int $companyId): array
    {
        $parsed = [];

        foreach ($rows as $index => $cells) {
            $cells = array_values((array) $cells);

            if ($this->isEmpty($cells)) {
                continue;
            }

            if ($index === 0 && mb_strtolower($this->text($cells[0] ?? null)) === 'број на фактура') {
                continue;
            }

            $parsed[] = $this->parseRow($index + 1, $cells);
        }

        if (count($parsed) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Најмногу '.self::MAX_ROWS.' редови по прикачување.');
        }

        $this->matchPartners($parsed, $companyId);
        $this->checkNumbers($parsed, $companyId);

        foreach ($parsed as &$row) {
            $row['status'] = $row['errors'] !== [] ? 'error' : ($row['partner_id'] === null ? 'unmatched' : 'ready');
        }

        return $parsed;
    }

    private function parseRow(int $rowNumber, array $cells): array
    {
        $errors = [];

        $number = $this->text($cells[0] ?? null);
        if ($number === '') {
            $errors[] = 'Недостасува број на фактура.';
        }

        $date = $this->date($cells[1] ?? null);
        if ($date === null) {
            $errors[] = 'Неважечки датум (очекувам ДД.ММ.ГГГГ).';
        }

        $name = $this->text($cells[2] ?? null);
        if ($name === '') {
            $errors[] = 'Недостасува кооперант.';
        }

        [$total, $totalValid] = $this->total($cells[4] ?? null);
        if (! $totalValid) {
            $errors[] = 'Неважечки износ (бројка со најмногу две децимали, без точка за илјади).';
        }

        return [
            'row_number' => $rowNumber,
            'number' => $number,
            'date' => $date,
            'partner_name' => $name,
            'tax_id' => $this->text($cells[3] ?? null),
            'total' => $total,
            'partner_id' => null,
            'status' => 'ready',
            'errors' => $errors,
        ];
    }

    private function matchPartners(array &$parsed, int $companyId): void
    {
        $byTax = [];
        $byName = [];

        foreach (Partner::where('company_id', $companyId)->get(['id', 'name', 'tax_id']) as $partner) {
            if (filled($partner->tax_id)) {
                $byTax[trim($partner->tax_id)][] = $partner->id;
            }

            $byName[self::normalizeName($partner->name)][] = $partner->id;
        }

        foreach ($parsed as &$row) {
            $taxMatches = $row['tax_id'] !== '' ? ($byTax[$row['tax_id']] ?? []) : [];
            $nameMatches = $row['partner_name'] !== '' ? ($byName[self::normalizeName($row['partner_name'])] ?? []) : [];

            if (count($taxMatches) === 1) {
                $row['partner_id'] = $taxMatches[0];
            } elseif (count($nameMatches) === 1) {
                $row['partner_id'] = $nameMatches[0];
            }
        }
    }

    /** Дупликат е ист број во иста година — во базата или претходно во истиот лист. */
    private function checkNumbers(array &$parsed, int $companyId): void
    {
        $numbers = collect($parsed)->where('number', '!=', '')->pluck('number')->unique()->values()->all();

        $existing = [];

        foreach (array_chunk($numbers, 500) as $chunk) {
            $found = SalesInvoice::where('company_id', $companyId)
                ->whereIn('invoice_number_formatted', $chunk)
                ->get(['invoice_number_formatted', 'invoice_date']);

            foreach ($found as $invoice) {
                $existing[$invoice->invoice_number_formatted.'|'.$invoice->invoice_date->year] = true;
            }
        }

        $seen = [];

        foreach ($parsed as &$row) {
            if ($row['number'] === '' || $row['date'] === null) {
                continue;
            }

            $year = (int) substr($row['date'], 0, 4);
            $key = $row['number'].'|'.$year;

            if (isset($existing[$key])) {
                $row['errors'][] = "Бројот веќе постои во {$year}.";
            } elseif (isset($seen[$key])) {
                $row['errors'][] = "Бројот се повторува во прикачувањето (ред {$seen[$key]}).";
            } else {
                $seen[$key] = $row['row_number'];
            }
        }
    }

    private static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->text($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === '') {
            return null;
        }

        // Вистинска датум-ќелија во Excel стигнува како сериски број.
        if (preg_match('/^\d+(\.\d+)?$/', $text) === 1) {
            $serial = (float) $text;

            // 2000-01-01 .. 2099-12-31; сè друго е бројка што не е датум.
            if ($serial < 36526 || $serial > 73415) {
                return null;
            }

            return Date::excelToDateTimeObject($serial)->format('Y-m-d');
        }

        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $text : null;
        }

        return null;
    }

    /** @return array{0: ?string, 1: bool} износ (2 децимали) и дали е важечки */
    private function total(mixed $value): array
    {
        $text = str_replace([' ', "\u{00A0}"], '', $this->text($value));

        if ($text === '') {
            return [null, true];
        }

        $text = str_replace(',', '.', $text);

        // Најмногу две децимали: износот од хартија не се заокружува тивко.
        if (! Bcmath::isPlainNumber($text) || preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1) {
            return [null, false];
        }

        return [bcadd($text, '0', 2), true];
    }
}
