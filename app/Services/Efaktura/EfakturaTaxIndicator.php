<?php

namespace App\Services\Efaktura;

class EfakturaTaxIndicator
{
    public static function code(string $vatTreatment, string $vatRate): string
    {
        return self::resolve($vatTreatment, $vatRate)[0];
    }

    public static function percent(string $vatTreatment, string $vatRate): float
    {
        return self::resolve($vatTreatment, $vatRate)[1];
    }

    /**
     * Reverse of code(): maps a УЈП tax indicator code back onto a flat vat_rate string, for
     * incoming purchase invoices. purchase_invoice_lines has no vat_treatment column (unlike
     * sales_invoice_lines) — only vat_rate — so exempt/export codes all collapse to '0.00'.
     * Returns null for any code with no supported mapping (member-32/32-а reverse-charge,
     * "not subject to tax", or anything else not in the forward table) — the caller is
     * responsible for flagging such a line for manual review rather than guessing a rate.
     */
    public static function fromCode(string $code): ?string
    {
        return match ($code) {
            'DDV-A' => '18.00',
            'DDV-V' => '10.00',
            'DDV-B' => '5.00',
            'DDV-G', 'DDV-7-I', 'DDV-8', 'DDV-9' => '0.00',
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: float}
     */
    private static function resolve(string $vatTreatment, string $vatRate): array
    {
        return match (true) {
            $vatTreatment === 'standard' && bccomp($vatRate, '18.00', 2) === 0 => ['DDV-A', 18.0],
            $vatTreatment === 'standard' && bccomp($vatRate, '10.00', 2) === 0 => ['DDV-V', 10.0],
            $vatTreatment === 'standard' && bccomp($vatRate, '5.00', 2) === 0 => ['DDV-B', 5.0],
            $vatTreatment === 'standard' && bccomp($vatRate, '0.00', 2) === 0 => ['DDV-G', 0.0],
            $vatTreatment === 'export' => ['DDV-7-I', 0.0],
            $vatTreatment === 'exempt_with_credit' => ['DDV-8', 0.0],
            $vatTreatment === 'exempt_without_credit' => ['DDV-9', 0.0],
            default => throw new \InvalidArgumentException(
                "Нема познат УЈП даночен индикатор за третман='{$vatTreatment}', стапка='{$vatRate}'."
            ),
        };
    }
}
