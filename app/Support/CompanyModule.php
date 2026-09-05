<?php

namespace App\Support;

/**
 * Што користи оваа фирма од апликацијата.
 *
 * За разлика од `CompanyType`, кој се избира еднаш и не се менува, модулите се
 * менуваат: клиент вработува првиот работник во март и тогаш се вклучува Плата.
 *
 * Залиха е подмодул на Материјално — самата нема смисла без влезни и излезни
 * документи. Тоа правило живее во `Company::usesModule()`, не тука, зашто enum
 * не знае што пишува во редот.
 *
 * Модулите важат само за правно лице. Кај физичко лице `CompanyType` веќе
 * одлучува што се гледа.
 */
enum CompanyModule: string
{
    case MATERIAL = 'material';
    case STOCK = 'stock';
    case PAYROLL = 'payroll';
    case FINANCE = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::MATERIAL => 'Материјално работење',
            self::STOCK => 'Залиха',
            self::PAYROLL => 'Плата',
            self::FINANCE => 'Финансии',
        };
    }

    /** Колоната на `companies` што го чува овој модул. */
    public function column(): string
    {
        return match ($this) {
            self::MATERIAL => 'uses_material',
            self::STOCK => 'uses_stock',
            self::PAYROLL => 'uses_payroll',
            self::FINANCE => 'uses_finance',
        };
    }
}
