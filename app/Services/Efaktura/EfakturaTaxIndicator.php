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
