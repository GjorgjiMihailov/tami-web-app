<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ги смета бројките што почетниот екран на клиентски профил ги прикажува:
 * приход/трошок за работната година, ненаплатено побарување/недоплатена
 * обврска (вкупно и само доспеаното), и бројот на неуспешни е-Фактура
 * испраќања.
 *
 * Секое сумирање минува низ App\Models\Concerns\HasInvoiceTotals
 * (grandTotal(), balanceDue()), кој заокружува по ставка со bcmath —
 * истата аритметика која самата фактура ја прикажува на својот екран.
 * Намерно НЕ се користи SQL SUM над sales_invoice_lines/purchase_invoice_lines,
 * зашто тоа заокружува поинаку и би се разминало за денар со екранот на
 * фактурата (истата класа грешка како во фаза 5c меѓу ниво 2 и 3 на МПИН).
 * За канцеларија со стотици фактури годишно, вчитување со ->with('lines')
 * и собирање во PHP е сосема доволно.
 */
class CompanyDashboardQuery
{
    /**
     * Вкупен приход: збир на grandTotal() на потврдените (status=confirmed)
     * излезни фактури чија invoice_date паѓа во дадената година.
     * Намерно НЕ брои нацрт-фактури — сè уште не се издадени, значи не се
     * приход — ниту фактури со invoice_date надвор од годината.
     */
    public static function revenue(Company $company, int $year): string
    {
        return self::sumGrandTotals(
            self::confirmedSalesInvoicesQuery($company, $year)->get()
        );
    }

    /**
     * Вкупен трошок: истото за влезните фактури.
     * Намерно НЕ брои нацрт-фактури ниту фактури од друга година.
     */
    public static function costs(Company $company, int $year): string
    {
        return self::sumGrandTotals(
            self::confirmedPurchaseInvoicesQuery($company, $year)->get()
        );
    }

    /**
     * Ненаплатено побарување: збир на balanceDue() (издадено минус
     * уплатено) на потврдените излезни фактури од годината. Намерно НЕ ги
     * ограничува на доспеани фактури — тоа е receivableOverdue().
     */
    public static function receivable(Company $company, int $year): string
    {
        return self::sumBalanceDue(
            self::confirmedSalesInvoicesQuery($company, $year)->with('payments')->get()
        );
    }

    /**
     * Истото, ограничено на фактури чиј due_date веќе поминал и кои сè
     * уште не се целосно платени (isOverdue()). Намерно НЕ брои фактура
     * што е веќе платена, дури и по due_date — таа не е доспеана обврска.
     */
    public static function receivableOverdue(Company $company, int $year): string
    {
        return self::sumBalanceDue(
            self::confirmedSalesInvoicesQuery($company, $year)->with('payments')->get()
                ->filter(fn (SalesInvoice $invoice) => $invoice->isOverdue())
        );
    }

    /**
     * Недоплатена обврска кон добавувачи: истото за влезните фактури.
     */
    public static function payable(Company $company, int $year): string
    {
        return self::sumBalanceDue(
            self::confirmedPurchaseInvoicesQuery($company, $year)->with('payments')->get()
        );
    }

    /**
     * Истото, ограничено на доспеани, сè уште неплатени влезни фактури.
     */
    public static function payableOverdue(Company $company, int $year): string
    {
        return self::sumBalanceDue(
            self::confirmedPurchaseInvoicesQuery($company, $year)->with('payments')->get()
                ->filter(fn (PurchaseInvoice $invoice) => $invoice->isOverdue())
        );
    }

    /**
     * Брои излезни фактури со efaktura_status = 'failed' чија invoice_date
     * паѓа во дадената година. Намерно НЕ бара status=confirmed одделно —
     * efaktura_status добива вредност само по обид за испраќање, а тоа е
     * можно само за потврдена фактура, така што филтерот е веќе доволен.
     */
    public static function efakturaFailed(Company $company, int $year): int
    {
        return SalesInvoice::query()
            ->where('company_id', $company->id)
            ->whereYear('invoice_date', $year)
            ->where('efaktura_status', 'failed')
            ->count();
    }

    private static function confirmedSalesInvoicesQuery(Company $company, int $year): Builder
    {
        return SalesInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->whereYear('invoice_date', $year)
            ->with('lines');
    }

    private static function confirmedPurchaseInvoicesQuery(Company $company, int $year): Builder
    {
        return PurchaseInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->whereYear('invoice_date', $year)
            ->with('lines');
    }

    private static function sumGrandTotals(Collection $invoices): string
    {
        return $invoices->reduce(fn (string $carry, $invoice) => bcadd($carry, $invoice->grandTotal(), 2), '0.00');
    }

    private static function sumBalanceDue(Collection $invoices): string
    {
        return $invoices->reduce(fn (string $carry, $invoice) => bcadd($carry, $invoice->balanceDue(), 2), '0.00');
    }
}
