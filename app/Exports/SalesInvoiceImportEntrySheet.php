<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SalesInvoiceImportEntrySheet implements FromArray, WithEvents, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Фактури';
    }

    public function headings(): array
    {
        return SalesInvoiceImportTemplateExport::HEADINGS;
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = SalesInvoiceImportTemplateExport::maxRows() + 1;

                // Бројот и ЕДБ како текст: инаку Excel го сече „00001" на 1.
                foreach (['A', 'D'] as $column) {
                    $sheet->getStyle("{$column}2:{$column}{$last}")
                        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }

                $sheet->getStyle('A1:E1')->getFont()->setBold(true);

                foreach (['A' => 18, 'B' => 20, 'C' => 40, 'D' => 20, 'E' => 34] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }
            },
        ];
    }
}
