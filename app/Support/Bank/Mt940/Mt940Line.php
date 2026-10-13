<?php

namespace App\Support\Bank\Mt940;

use App\Support\Bank\LineDirection;

/** Една ставка од извод. Износот е секогаш позитивен; насоката е од наша гледна точка. */
final class Mt940Line
{
    public function __construct(
        public readonly string $date,
        public readonly LineDirection $direction,
        public readonly string $amount,
        public readonly ?string $counterpartyAccount,
        public readonly ?string $counterpartyName,
        public readonly ?string $referenceNumber,
        public readonly string $description,
        public readonly ?string $bankReference,
    ) {}

    /** + за уплата, − за исплата. */
    public function signedAmount(): string
    {
        return $this->direction === LineDirection::OUT ? bcmul($this->amount, '-1', 2) : bcadd($this->amount, '0', 2);
    }
}
