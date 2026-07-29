<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка',
            'Продажна цена', 'Тип', 'МК-производство', 'Баркод',
        ];
    }

    public function array(): array
    {
        return [
            ['SKU-001', 'Пример артикл', 'парче', 'Пример категорија', '18', '250.00', 'производ', 'Да', '3800000000017'],
        ];
    }
}
