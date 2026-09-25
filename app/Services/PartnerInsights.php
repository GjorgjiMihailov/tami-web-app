<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Податоците за екранот на кооперанти: колку ни должи купувачот, што сме му
 * фактурирале и наплатиле, и изводот на сметка.
 *
 * Сите суми минуваат низ HasInvoiceTotals (bcmath, заокружување по ставка) —
 * истата аритметика што ја покажува самата фактура, за екранот и фактурата
 * никогаш да не се разминат за денар. Побарувањата се по валута: девизна
 * фактура се води во својата валута и никогаш не се меша со денарите.
 */
class PartnerInsights
{
    public const PERIODS = ['this_month', 'last_month', 'this_year', 'last_year', 'all'];

    public const INVOICE_FILTERS = ['all', 'draft', 'unpaid', 'partially_paid', 'paid'];

    /** Ненаплатено по кооперант и валута, за листата лево: [partner_id => [валута => износ]]. */
    public static function outstandingByPartner(Company $company): array
    {
        $totals = [];

        SalesInvoice::where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->each(function (SalesInvoice $invoice) use (&$totals) {
                $current = $totals[$invoice->partner_id][$invoice->currency] ?? '0.00';
                $totals[$invoice->partner_id][$invoice->currency] = bcadd($current, $invoice->balanceDue(), 2);
            });

        return $totals;
    }

    /**
     * Побарувања од купувачот по валута.
     *
     * @return Collection<int, array{currency: string, outstanding: string, overdue: string}>
     */
    public static function receivables(Partner $partner): Collection
    {
        $rows = [];

        SalesInvoice::where('partner_id', $partner->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->each(function (SalesInvoice $invoice) use (&$rows) {
                $row = $rows[$invoice->currency] ??= ['currency' => $invoice->currency, 'outstanding' => '0.00', 'overdue' => '0.00'];
                $row['outstanding'] = bcadd($row['outstanding'], $invoice->balanceDue(), 2);

                if ($invoice->isOverdue()) {
                    $row['overdue'] = bcadd($row['overdue'], $invoice->balanceDue(), 2);
                }

                $rows[$invoice->currency] = $row;
            });

        return collect($rows)->sortKeys()->values();
    }

    /** Неплатено кон кооперантот како добавувач (влезни фактури, денари). */
    public static function payables(Partner $partner): string
    {
        return PurchaseInvoice::where('partner_id', $partner->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->reduce(fn (string $carry, PurchaseInvoice $invoice) => bcadd($carry, $invoice->balanceDue(), 2), '0.00');
    }

    /**
     * Фактурите на кооперантот (излезни) со рабо-статус за приказ.
     *
     * @return Collection<int, array{invoice: SalesInvoice, total: string, balance: ?string, status: string}>
     */
    public static function salesInvoices(Partner $partner, string $filter, int $limit = 100): Collection
    {
        return SalesInvoice::where('partner_id', $partner->id)
            ->with(['lines', 'payments'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->get()
            ->map(fn (SalesInvoice $invoice) => [
                'invoice' => $invoice,
                'total' => $invoice->grandTotal(),
                'balance' => $invoice->status === 'confirmed' ? $invoice->balanceDue() : null,
                'status' => $invoice->status === 'confirmed' ? $invoice->paymentStatus() : $invoice->status,
            ])
            ->filter(fn (array $row) => $filter === 'all' || $row['status'] === $filter)
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, array{payment: \App\Models\SalesInvoicePayment, invoice: SalesInvoice}> */
    public static function salesPayments(Partner $partner, int $limit = 100): Collection
    {
        return SalesInvoice::where('partner_id', $partner->id)
            ->with('payments')
            ->get()
            ->flatMap(fn (SalesInvoice $invoice) => $invoice->payments->map(fn ($payment) => ['payment' => $payment, 'invoice' => $invoice]))
            ->sortByDesc(fn (array $row) => $row['payment']->payment_date->toDateString())
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, array{invoice: PurchaseInvoice, total: string, balance: ?string, status: string}> */
    public static function purchaseInvoices(Partner $partner, int $limit = 100): Collection
    {
        return PurchaseInvoice::where('partner_id', $partner->id)
            ->with(['lines', 'payments'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseInvoice $invoice) => [
                'invoice' => $invoice,
                'total' => $invoice->grandTotal(),
                'balance' => $invoice->status === 'confirmed' ? $invoice->balanceDue() : null,
                'status' => $invoice->status === 'confirmed' ? $invoice->paymentStatus() : $invoice->status,
            ])
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, array{payment: \App\Models\PurchaseInvoicePayment, invoice: PurchaseInvoice}> */
    public static function purchasePayments(Partner $partner, int $limit = 100): Collection
    {
        return PurchaseInvoice::where('partner_id', $partner->id)
            ->with('payments')
            ->get()
            ->flatMap(fn (PurchaseInvoice $invoice) => $invoice->payments->map(fn ($payment) => ['payment' => $payment, 'invoice' => $invoice]))
            ->sortByDesc(fn (array $row) => $row['payment']->payment_date->toDateString())
            ->take($limit)
            ->values();
    }

    /** @return array{0: ?CarbonImmutable, 1: CarbonImmutable} */
    public static function periodBounds(string $period, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return match ($period) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'all' => [null, $today],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
    }

    /**
     * Извод на сметка на купувачот, по валута: почетно салдо, фактурирано и
     * уплатено во периодот, и тековен збир по ред. Се брои само потврдените фактури.
     *
     * @return array{from: ?CarbonImmutable, to: CarbonImmutable, currencies: array<string, array{opening: string, invoiced: string, received: string, closing: string, rows: array<int, array{date: string, type: string, document: string, details: string, amount: ?string, payment: ?string, balance: string}>}>}
     */
    public static function statement(Partner $partner, string $period, ?CarbonImmutable $today = null): array
    {
        [$from, $to] = self::periodBounds($period, $today);

        $invoices = SalesInvoice::where('partner_id', $partner->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get();

        $events = [];

        foreach ($invoices as $invoice) {
            $number = $invoice->formattedNumber() ?? '#'.$invoice->id;

            $events[] = [
                'currency' => $invoice->currency,
                'date' => $invoice->invoice_date->toDateString(),
                'order' => 0,
                'type' => 'invoice',
                'document' => $number,
                'details' => '',
                'amount' => $invoice->grandTotal(),
            ];

            foreach ($invoice->payments as $payment) {
                $events[] = [
                    'currency' => $invoice->currency,
                    'date' => $payment->payment_date->toDateString(),
                    'order' => 1,
                    'type' => 'payment',
                    'document' => $number,
                    'details' => \App\Support\Format::paymentMethod((string) $payment->payment_method),
                    'amount' => (string) $payment->amount,
                ];
            }
        }

        $fromDate = $from?->toDateString();
        $toDate = $to->toDateString();
        $currencies = [];

        foreach (collect($events)->groupBy('currency')->sortKeys() as $currency => $group) {
            $opening = '0.00';
            $invoiced = '0.00';
            $received = '0.00';
            $rows = [];

            $sorted = $group->sortBy([['date', 'asc'], ['order', 'asc']])->values();

            foreach ($sorted as $event) {
                if ($event['date'] > $toDate) {
                    continue;
                }

                $isInvoice = $event['type'] === 'invoice';

                if ($fromDate !== null && $event['date'] < $fromDate) {
                    $opening = $isInvoice ? bcadd($opening, $event['amount'], 2) : bcsub($opening, $event['amount'], 2);

                    continue;
                }

                $isInvoice ? $invoiced = bcadd($invoiced, $event['amount'], 2) : $received = bcadd($received, $event['amount'], 2);

                $rows[] = $event;
            }

            $balance = $opening;
            $out = [];

            foreach ($rows as $event) {
                $balance = $event['type'] === 'invoice' ? bcadd($balance, $event['amount'], 2) : bcsub($balance, $event['amount'], 2);
                $out[] = [
                    'date' => $event['date'],
                    'type' => $event['type'],
                    'document' => $event['document'],
                    'details' => $event['details'],
                    'amount' => $event['type'] === 'invoice' ? $event['amount'] : null,
                    'payment' => $event['type'] === 'payment' ? $event['amount'] : null,
                    'balance' => $balance,
                ];
            }

            $currencies[$currency] = [
                'opening' => $opening,
                'invoiced' => $invoiced,
                'received' => $received,
                'closing' => bcsub(bcadd($opening, $invoiced, 2), $received, 2),
                'rows' => $out,
            ];
        }

        if ($currencies === []) {
            $currencies['MKD'] = ['opening' => '0.00', 'invoiced' => '0.00', 'received' => '0.00', 'closing' => '0.00', 'rows' => []];
        }

        return ['from' => $from, 'to' => $to, 'currencies' => $currencies];
    }
}
