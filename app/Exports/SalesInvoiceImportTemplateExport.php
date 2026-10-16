<?php

namespace App\Exports;

use App\Services\Invoicing\SalesInvoiceImportParser;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Образец за масовен внес на излезни фактури. Првиот лист е оној што се чита;
 * вториот е само упатство. Намерно нема примерен ред на првиот лист — оставен
 * по грешка, би се увезол како вистинска фактура.
 */
class SalesInvoiceImportTemplateExport implements WithMultipleSheets
{
    public const HEADINGS = [
        'Број на фактура',
        'Датум на издавање',
        'Кооперант',
        'ЕДБ на кооперант',
        'Вкупно од хартија (незадолжително)',
    ];

    public function sheets(): array
    {
        return [
            new SalesInvoiceImportEntrySheet,
            new SalesInvoiceImportInstructionsSheet,
        ];
    }

    public static function maxRows(): int
    {
        return SalesInvoiceImportParser::MAX_ROWS;
    }
}
