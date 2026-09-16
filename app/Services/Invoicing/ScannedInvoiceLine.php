<?php

namespace App\Services\Invoicing;

/**
 * Една ставка како што била прочитана од скен.
 *
 * Сè е стринг и сè е незадолжително намерно: скенот е слика на хартија, не
 * база. Прочитано „количина: —" мора да може да помине до формата и таму да го
 * види човек, наместо тука да пукне обработката.
 */
final readonly class ScannedInvoiceLine
{
    public function __construct(
        public ?string $description = null,
        public ?string $quantity = null,
        public ?string $unitPrice = null,
        public ?string $vatRate = null,
    ) {}
}
