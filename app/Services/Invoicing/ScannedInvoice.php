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
    ) {}
}
