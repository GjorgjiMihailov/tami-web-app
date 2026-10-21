<?php

namespace App\Services\Bank;

use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\Bank\LineDirection;
use Illuminate\Support\Collection;

/**
 * Отворените фактури на фирмата за еден увоз (еден или повеќе извода одеднаш).
 * Се читаат еднаш по насока, а салдата и веќе внесените плаќања се намалуваат
 * како ставките се препознаваат — две ставки од ист увоз не смеат да ја
 * „потрошат“ истата фактура двапати.
 */
class BankMatchSession
{
    /** @var array<string, Collection<int, OpenInvoice>> */
    private array $open = [];

    public function __construct(public readonly Company $company) {}

    /**
     * Уплата → излезни фактури (само денарски), исплата → влезни; само потврдени
     * со салдо поголемо од нула.
     *
     * @return Collection<int, OpenInvoice>
     */
    public function open(LineDirection $direction): Collection
    {
        return $this->open[$direction->value] ??= $this->load($direction);
    }

    public function reserve(OpenInvoice $invoice, string $amount): void
    {
        $left = bcsub($invoice->balance, $amount, 2);
        $invoice->balance = bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    public function takePayment(OpenInvoice $invoice, int $paymentId): void
    {
        unset($invoice->payments[$paymentId]);
    }

    /** @return Collection<int, OpenInvoice> */
    private function load(LineDirection $direction): Collection
    {
        $isIn = $direction === LineDirection::IN;
        $query = $isIn ? SalesInvoice::where('currency', 'MKD') : PurchaseInvoice::query();
        $linkColumn = $isIn ? 'sales_invoice_payment_id' : 'purchase_invoice_payment_id';
        $taken = BankStatementLine::whereNotNull($linkColumn)->pluck($linkColumn)->all();

        return $query->where('company_id', $this->company->id)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments', 'partner:id,name'])
            ->get()
            ->filter(fn ($invoice) => bccomp($invoice->balanceDue(), '0', 2) > 0)
            ->map(fn ($invoice) => new OpenInvoice(
                id: $invoice->id,
                partnerId: $invoice->partner_id,
                partnerName: $invoice->partner?->name,
                number: $isIn ? $invoice->formattedNumber() : $invoice->supplier_invoice_number,
                date: $invoice->invoice_date->toDateString(),
                balance: $invoice->balanceDue(),
                payments: $invoice->payments
                    ->where('payment_method', 'bank')
                    ->reject(fn ($payment) => in_array($payment->id, $taken, true))
                    ->mapWithKeys(fn ($payment) => [$payment->id => bcadd((string) $payment->amount, '0', 2)])
                    ->all(),
            ))
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->values();
    }
}
