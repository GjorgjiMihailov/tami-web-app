<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\Bcmath;

/**
 * Неплатено кон/од партнери, за таблата на Финансии.
 *
 * За разлика од картичките на таблата на фирмата, ова НЕ е врзано за година:
 * побарување од минатата година е сè уште побарување. Сè во денари — девизна
 * излезна фактура се претвора по курсот запишан на неа.
 */
class OpenInvoicesQuery
{
    /** @return array{total: string, current: string, overdue: string} */
    public static function receivables(Company $company): array
    {
        $invoices = SalesInvoice::where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get();

        return self::summarize($invoices, fn (SalesInvoice $invoice, string $balance) => $invoice->isForeignCurrency()
            ? Bcmath::roundHalfUp(bcmul($balance, (string) $invoice->exchange_rate, 10), 2)
            : $balance);
    }

    /** @return array{total: string, current: string, overdue: string} */
    public static function payables(Company $company): array
    {
        $invoices = PurchaseInvoice::where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get();

        return self::summarize($invoices, fn ($invoice, string $balance) => $balance);
    }

    /**
     * @param  iterable<SalesInvoice|PurchaseInvoice>  $invoices
     * @param  callable(SalesInvoice|PurchaseInvoice, string): string  $toMkd
     * @return array{total: string, current: string, overdue: string}
     */
    private static function summarize(iterable $invoices, callable $toMkd): array
    {
        $current = '0.00';
        $overdue = '0.00';

        foreach ($invoices as $invoice) {
            $balance = $invoice->balanceDue();

            if (bccomp($balance, '0', 2) <= 0) {
                continue;
            }

            $mkd = $toMkd($invoice, $balance);

            if ($invoice->isOverdue()) {
                $overdue = bcadd($overdue, $mkd, 2);
            } else {
                $current = bcadd($current, $mkd, 2);
            }
        }

        return ['total' => bcadd($current, $overdue, 2), 'current' => $current, 'overdue' => $overdue];
    }
}
