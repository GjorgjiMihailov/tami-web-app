<?php

namespace App\Support;

use App\Models\Company;

/**
 * Единственото место што склопува број на фактура од поставките на фирмата.
 *
 * Пред ова, `fiscal_year` и `invoice_number` се лепеа рачно на осум места, со
 * два различни разделника — на PDF-от коса црта, кон УЈП цртичка. Секое ново
 * место што прикажува број мора да поминува оттука.
 */
class InvoiceNumber
{
    public static function format(Company $company, int $fiscalYear, int $sequence): string
    {
        $prefix = (string) ($company->invoice_number_prefix ?? '');

        // Стеснувањето е одбрана од невалидна вредност во базата, не замена за
        // валидација на екранот — str_pad со должина 0 би вратил празно.
        $padding = max(1, min(6, (int) $company->invoice_number_padding));
        $number = str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);

        if (! $company->invoice_number_include_year) {
            return $prefix.$number;
        }

        $year = ((int) $company->invoice_number_year_digits) === 2
            ? substr((string) $fiscalYear, -2)
            : (string) $fiscalYear;

        $separator = (string) ($company->invoice_number_separator ?? '');

        return $company->invoice_number_year_first
            ? $prefix.$year.$separator.$number
            : $prefix.$number.$separator.$year;
    }
}
