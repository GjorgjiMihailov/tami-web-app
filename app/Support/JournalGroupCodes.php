<?php

namespace App\Support;

use App\Models\Company;
use App\Models\JournalGroup;

/**
 * Групите на налози (кодот е бројот пред цртата во 30-0001). Мапата ја даде
 * сопственикот на 2026-10-11:
 *
 *   00  почетна состојба на годината
 *   10  денарски изводи и плаќања од основната (денарска) сметка
 *   11  девизни изводи
 *   20  влезни фактури (еден налог месечно)
 *   30  излезни фактури (еден налог месечно)
 *   50  плата
 *   51  е-ПДД исплати: договор на дело (повремени и привремени)
 *   52  кирија
 *   53  регрес
 *   90  затворање на годината
 *   99  останато автоматско (готовински плаќања на фактури)
 *
 * 51–53 и 90 сè уште немаат изградена функција што книжи во нив — кодот ги
 * чува за да не се измислува втор пат.
 */
final class JournalGroupCodes
{
    public const OPENING = '00';

    public const DENAR_BANK = '10';

    public const FOREIGN_BANK = '11';

    public const PURCHASE_INVOICES = '20';

    public const SALES_INVOICES = '30';

    public const PAYROLL = '50';

    public const CONTRACT_WORK = '51';

    public const RENT = '52';

    public const REGRES = '53';

    public const YEAR_CLOSING = '90';

    public const OTHER_AUTOMATIC = '99';

    private const NAMES = [
        self::OPENING => 'Почетна состојба',
        self::DENAR_BANK => 'Денарски изводи и плаќања',
        self::FOREIGN_BANK => 'Девизни изводи',
        self::PURCHASE_INVOICES => 'Влезни фактури',
        self::SALES_INVOICES => 'Излезни фактури',
        self::PAYROLL => 'Плата',
        self::CONTRACT_WORK => 'Исплати — договор на дело',
        self::RENT => 'Кирија',
        self::REGRES => 'Регрес',
        self::YEAR_CLOSING => 'Затворање на годината',
        self::OTHER_AUTOMATIC => 'Автоматски (останато)',
    ];

    /** Групата на фирмата за дадениот код; се создава при прва употреба. */
    public static function group(Company $company, string $code): JournalGroup
    {
        return JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => $code],
            ['name' => self::NAMES[$code], 'sort_order' => (int) $code]
        );
    }

    public static function name(string $code): string
    {
        return self::NAMES[$code];
    }
}
