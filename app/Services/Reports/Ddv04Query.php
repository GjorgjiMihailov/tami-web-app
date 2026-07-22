<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoiceLine;
use App\Support\Bcmath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Ddv04Query
{
    public static function run(Company $company, Carbon $from, Carbon $to): array
    {
        $salesLines = SalesInvoiceLine::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('sales_invoices.company_id', $company->id)
            ->where('sales_invoices.status', 'confirmed')
            ->whereBetween('sales_invoices.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->get(['sales_invoice_lines.*']);

        $purchaseLines = PurchaseInvoiceLine::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->where('purchase_invoices.company_id', $company->id)
            ->where('purchase_invoices.status', 'confirmed')
            ->where('purchase_invoice_lines.vat_deductible', true)
            ->whereBetween('purchase_invoices.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->get(['purchase_invoice_lines.*']);

        $standardByRate = fn (string $rate) => $salesLines->filter(
            fn (SalesInvoiceLine $line) => $line->vat_treatment === 'standard' && bccomp((string) $line->vat_rate, $rate, 2) === 0
        );

        $field01 = self::sumBase($standardByRate('18.00'));
        $field02 = self::sumVat($standardByRate('18.00'));
        $field03 = self::sumBase($standardByRate('10.00'));
        $field04 = self::sumVat($standardByRate('10.00'));
        $field05 = self::sumBase($standardByRate('5.00'));
        $field06 = self::sumVat($standardByRate('5.00'));

        $field07 = self::sumBase($salesLines->filter(fn (SalesInvoiceLine $line) => $line->vat_treatment === 'export'));
        $field08 = self::sumBase($salesLines->filter(fn (SalesInvoiceLine $line) => $line->vat_treatment === 'exempt_with_credit'));
        $field09 = self::sumBase($salesLines->filter(fn (SalesInvoiceLine $line) => $line->vat_treatment === 'exempt_without_credit'));

        $field20 = Bcmath::roundHalfUp(bcadd(bcadd($field02, $field04, 10), $field06, 10), 2);

        $field21 = self::sumBase($purchaseLines);
        $field22 = self::sumVat($purchaseLines);
        $field29 = $field22;
        $field30 = '0.00';
        $field31 = Bcmath::roundHalfUp(bcsub(bcsub($field20, $field29, 10), $field30, 10), 2);

        return [
            '01' => $field01, '02' => $field02,
            '03' => $field03, '04' => $field04,
            '05' => $field05, '06' => $field06,
            '07' => $field07,
            '08' => $field08,
            '09' => $field09,
            '20' => $field20,
            '21' => $field21, '22' => $field22,
            '29' => $field29,
            '30' => $field30,
            '31' => $field31,
        ];
    }

    private static function sumBase(Collection $lines): string
    {
        return Bcmath::roundHalfUp(
            $lines->reduce(fn (string $carry, $line) => bcadd($carry, $line->lineTotal(), 10), '0'),
            2
        );
    }

    private static function sumVat(Collection $lines): string
    {
        return Bcmath::roundHalfUp(
            $lines->reduce(fn (string $carry, $line) => bcadd($carry, $line->vatAmount(), 10), '0'),
            2
        );
    }
}
