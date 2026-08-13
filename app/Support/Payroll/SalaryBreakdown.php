<?php

namespace App\Support\Payroll;

/**
 * One salary calculation. All properties carry two decimals; whole denars are
 * produced only by whole(), because rounding to a whole number is МПИН's
 * write rule and not a step in the calculation.
 */
readonly class SalaryBreakdown
{
    public function __construct(
        public float $gross,
        public float $pension,
        public float $health,
        public float $injury,
        public float $unemployment,
        public float $contributions,
        public float $taxBase,
        public float $tax,
        public float $net,
        public float $topUpPension,
        public float $topUpHealth,
        public float $topUpInjury,
        public float $topUpUnemployment,
        public float $topUp,
    ) {}

    /** @return array<string, int> */
    public function whole(): array
    {
        return [
            'gross' => (int) round($this->gross),
            'pension' => (int) round($this->pension),
            'health' => (int) round($this->health),
            'injury' => (int) round($this->injury),
            'unemployment' => (int) round($this->unemployment),
            'contributions' => (int) round($this->contributions),
            'taxBase' => (int) round($this->taxBase),
            'tax' => (int) round($this->tax),
            'net' => (int) round($this->net),
            'topUpPension' => (int) round($this->topUpPension),
            'topUpHealth' => (int) round($this->topUpHealth),
            'topUpInjury' => (int) round($this->topUpInjury),
            'topUpUnemployment' => (int) round($this->topUpUnemployment),
            'topUp' => (int) round($this->topUp),
        ];
    }
}
