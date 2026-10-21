<?php

namespace App\Services\Bank;

/** Отворена фактура како што ја гледа матчерот: салдото и неврзаните банкарски плаќања се менливи во рамките на еден увоз. */
final class OpenInvoice
{
    /**
     * @param  array<int, string>  $payments  неврзани банкарски плаќања: id → износ
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $partnerId,
        public readonly ?string $partnerName,
        public readonly ?string $number,
        public readonly string $date,
        public string $balance,
        public array $payments = [],
    ) {}

    public function label(): string
    {
        return $this->number ?: '#'.$this->id;
    }
}
