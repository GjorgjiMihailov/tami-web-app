<?php

namespace App\Services\Invoicing;

/**
 * Една ставка од ЕЦД како што била прочитана од скен.
 *
 * `charges` е код на давачка → износ (A00 = царина, B00 = ДДВ). Сè е стринг и
 * незадолжително: скенот е фотографија, не база.
 */
final readonly class ScannedCustomsItem
{
    /**
     * @param  array<string, string>  $charges
     */
    public function __construct(
        public ?string $tariffCode = null,
        public ?string $description = null,
        public ?string $invoiceValueForeign = null,
        public ?string $statisticalValue = null,
        public array $charges = [],
    ) {}
}
