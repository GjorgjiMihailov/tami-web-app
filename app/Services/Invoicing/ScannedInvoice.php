<?php

namespace App\Services\Invoicing;

/**
 * Сè што е прочитано од еден скен, пред каква било проверка.
 *
 * `sellerTaxId` постои само за една работа: да се провери дали качениот фајл е
 * навистина излезна фактура на оваа фирма, а не влезна. `printedTotal` е
 * вкупното испишано на хартијата и служи за спротивставување со пресметаното.
 */
final readonly class ScannedInvoice
{
    /**
     * @param  ScannedInvoiceLine[]  $lines
     */
    public function __construct(
        public ?string $sellerTaxId = null,
        public ?string $buyerName = null,
        public ?string $buyerTaxId = null,
        public ?string $buyerStreetAddress = null,
        public ?string $buyerStreetNumber = null,
        public ?string $buyerPostalCode = null,
        public ?string $buyerCity = null,
        public ?string $invoiceNumber = null,
        public ?string $invoiceDate = null,
        public ?string $dueDate = null,
        public ?string $currency = null,
        public ?string $printedTotal = null,
        public array $lines = [],
        // Името на издавачот се чита за да може човекот да види чија е
        // фактурата кога ЕДБ-то не се совпаѓа — само број не кажува ништо.
        public ?string $sellerName = null,
        // Колку одделни фактури има во фајлот. Врз вистински PDF со четири
        // фактури беше прочитана само првата, а другите три исчезнаа без збор.
        public ?int $invoiceCount = null,
        // Каде моделот ја најде оваа фирма на хартијата: 'seller', 'buyer' или
        // 'absent'. Врз вистински фактури името на издавачот беше точно секаде,
        // а ЕДБ-то мешано — па одлуката чија е фактурата се потпира на ова.
        public ?string $ourCompanyRole = null,
    ) {}
}
