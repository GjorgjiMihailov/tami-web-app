<?php

namespace App\Support\Payroll;

/**
 * One employee's month.
 *
 * The `employer*` figures are the subset that reaches the company's ledger.
 * They are not a second calculation: contributions and tax are computed once,
 * on the whole gross, then apportioned. See the spec's "Сразмерната поделба".
 */
readonly class PayrollRunResult
{
    /** @param list<PayrollRunLineResult> $lines */
    public function __construct(
        public float $hourlyRate,
        public array $lines,
        public float $gross,
        public SalaryBreakdown $breakdown,
        public float $deductionsTotal,
        public float $effectiveNet,
        public float $employerGross,
        public float $employerContributions,
        public float $employerTax,
        public float $employerNet,
    ) {}
}
