<?php

namespace App\Support\Bank\Mt940;

/** Еден прочитан извод. Состојбите се со знак од наша гледна точка: + = пари на сметка. */
final class Mt940Statement
{
    /** @param  list<Mt940Line>  $lines */
    public function __construct(
        public readonly string $account,
        public readonly int $number,
        public readonly string $date,
        public readonly string $opening,
        public readonly string $closing,
        public readonly array $lines,
        public readonly ?string $bic,
    ) {}

    /** Уплати минус исплати. */
    public function movement(): string
    {
        return array_reduce($this->lines, fn (string $carry, Mt940Line $line) => bcadd($carry, $line->signedAmount(), 2), '0.00');
    }

    /** Почетна + движење − крајна; нула значи дека изводот се совпаѓа. */
    public function difference(): string
    {
        return bcsub(bcadd($this->opening, $this->movement(), 2), $this->closing, 2);
    }
}
