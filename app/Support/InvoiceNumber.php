<?php

namespace App\Support;

use App\Models\Company;

/**
 * Единственото место што склопува број на фактура од поставките на фирмата.
 *
 * Порано, `fiscal_year` и `invoice_number` се лепеа рачно на десет места во
 * седум фајла, со два различни разделника — на PDF-от коса црта, кон УЈП
 * цртичка. Секое ново место што прикажува број мора да поминува оттука.
 *
 * Едно намерно исклучение останува: миграцијата
 * `database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php`
 * сепак лепи `fiscal_year.'/'.invoice_number` рачно, наместо преку оваа
 * класа. Мора — таа треба да ја запише точно старата ГГГГ/Н вредност за веќе
 * потврдени фактури, без оглед на форматот што фирмата подоцна ќе го избере.
 * Ако пополнувањето минуваше низ оваа класа, ќе го пресметуваше бројот од
 * тековните поставки на фирмата, и старите фактури би си го смениле бројот
 * наназад.
 */
class InvoiceNumber
{
    /**
     * $prefix го заменува префиксот на фактурата — профактурата ја дели целата
     * останата поставка (година, разделник, должина), а има свој префикс.
     */
    public static function format(Company $company, int $fiscalYear, int $sequence, ?string $prefix = null): string
    {
        $prefix ??= (string) ($company->invoice_number_prefix ?? '');

        // Горниот праг е одбрана од невалидна вредност во базата, не замена за
        // валидација на екранот. Долен праг нема потреба — str_pad никогаш не
        // крати стринг, а бројот секогаш дава барем една цифра.
        $padding = min(6, (int) $company->invoice_number_padding);
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
