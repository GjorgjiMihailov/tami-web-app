<?php

namespace App\Support\Payroll;

/** One calculated line. `amount` is always filled, whatever the kind. */
readonly class PayrollRunLineResult
{
    public function __construct(
        public string $kind,
        public ?string $code,
        public string $description,
        public ?int $hours,
        public ?float $percent,
        public float $amount,
        public string $borneBy,
        public bool $isAutomatic,
    ) {}
}
