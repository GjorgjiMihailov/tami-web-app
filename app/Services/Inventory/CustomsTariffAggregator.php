<?php

namespace App\Services\Inventory;

use App\Services\Invoicing\ScannedCustomsItem;

/**
 * Ги собира ставките од ЕЦД по тарифен број — истиот приказ што го дава
 * постојниот царински софтвер: еден ред по тарифа, со збир на фактурната
 * вредност, царината (A00) и ДДВ (B00). Чиста класа, без база и мрежа.
 */
class CustomsTariffAggregator
{
    public const DUTY_CODE = 'A00';

    public const VAT_CODE = 'B00';

    /**
     * @param  ScannedCustomsItem[]  $items
     * @return array{rows: array<int, array{tariff_code: string, foreign_amount: string, customs_duty: string, vat_amount: string}>, other_codes: string[], duty_total: string, vat_total: string, foreign_total: string}
     */
    public function aggregate(array $items): array
    {
        $rows = [];
        $otherCodes = [];
        $dutyTotal = '0.00';
        $vatTotal = '0.00';
        $foreignTotal = '0.00';

        foreach ($items as $item) {
            $code = $item->tariffCode !== null && $item->tariffCode !== '' ? $item->tariffCode : '—';

            $rows[$code] ??= ['tariff_code' => $code, 'foreign_amount' => '0.00', 'customs_duty' => '0.00', 'vat_amount' => '0.00'];

            $foreign = $this->amount($item->invoiceValueForeign);
            $duty = $this->amount($item->charges[self::DUTY_CODE] ?? null);
            $vat = $this->amount($item->charges[self::VAT_CODE] ?? null);

            $rows[$code]['foreign_amount'] = bcadd($rows[$code]['foreign_amount'], $foreign, 2);
            $rows[$code]['customs_duty'] = bcadd($rows[$code]['customs_duty'], $duty, 2);
            $rows[$code]['vat_amount'] = bcadd($rows[$code]['vat_amount'], $vat, 2);

            $foreignTotal = bcadd($foreignTotal, $foreign, 2);
            $dutyTotal = bcadd($dutyTotal, $duty, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);

            foreach (array_keys($item->charges) as $chargeCode) {
                if (! in_array($chargeCode, [self::DUTY_CODE, self::VAT_CODE], true)) {
                    $otherCodes[$chargeCode] = $chargeCode;
                }
            }
        }

        $otherCodes = array_values($otherCodes);
        sort($otherCodes);

        return [
            'rows' => array_values($rows),
            'other_codes' => $otherCodes,
            'duty_total' => $dutyTotal,
            'vat_total' => $vatTotal,
            'foreign_total' => $foreignTotal,
        ];
    }

    private function amount(?string $value): string
    {
        return $value !== null && is_numeric($value) ? $value : '0';
    }
}
