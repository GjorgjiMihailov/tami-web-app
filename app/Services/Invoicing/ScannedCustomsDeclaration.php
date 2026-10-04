<?php

namespace App\Services\Invoicing;

/**
 * Сè што е прочитано од еден скен на царинска декларација (ЕЦД), пред
 * каква било проверка. `totalDuty`/`totalVat` се ВКУПНО од последната
 * страна и служат само за спротивставување со збирот од ставките.
 */
final readonly class ScannedCustomsDeclaration
{
    /**
     * @param  string[]  $referencedInvoiceNumbers
     * @param  ScannedCustomsItem[]  $items
     */
    public function __construct(
        public ?string $declarationNumber = null,
        public ?string $date = null,
        public ?string $importerName = null,
        public ?string $importerTaxId = null,
        public ?string $declarantName = null,
        public ?string $currency = null,
        public ?string $invoiceTotalForeign = null,
        public ?string $exchangeRate = null,
        public ?string $totalDuty = null,
        public ?string $totalVat = null,
        public array $referencedInvoiceNumbers = [],
        public array $items = [],
    ) {}
}
