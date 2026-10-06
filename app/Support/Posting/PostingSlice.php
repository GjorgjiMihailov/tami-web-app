<?php

namespace App\Support\Posting;

/** Една кришка од документот: износ за вид на ставка × даночна група. */
final class PostingSlice
{
    public function __construct(
        public ItemKind $itemKind,
        public VatGroup $vatGroup,
        public string $base,
        public string $vat,
        public string $baseForeign = '0.00',
        public string $vatForeign = '0.00',
    ) {}
}
