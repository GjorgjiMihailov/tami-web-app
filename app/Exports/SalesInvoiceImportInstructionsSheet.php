<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;

class SalesInvoiceImportInstructionsSheet implements FromArray, WithColumnWidths, WithTitle
{
    public function title(): string
    {
        return 'Упатство';
    }

    public function columnWidths(): array
    {
        return ['A' => 110];
    }

    public function array(): array
    {
        return array_map(fn (string $line) => [$line], [
            'Пополнете го листот „Фактури": по еден ред за секоја фактура. Овој лист не се чита.',
            '',
            'Број на фактура — точно како на хартија (пр. 00001-26). Задолжително.',
            'Датум на издавање — ДД.ММ.ГГГГ (пр. 04.01.2026). Задолжително. Година со четири цифри.',
            'Кооперант — име на купувачот. Задолжително.',
            'ЕДБ на кооперант — незадолжително; ако го внесете, купувачот се препознава по него.',
            'Вкупно од хартија — незадолжително. Само бројка, со запирка или точка за децимали, без точка за илјади (пр. 2360 или 2360,50). Служи за контрола кога подоцна ќе ги додадете ставките.',
            '',
            'Најмногу '.SalesInvoiceImportTemplateExport::maxRows().' фактури по прикачување.',
            'Артиклите и количините ги додавате по увозот, на секоја фактура посебно.',
        ]);
    }
}
