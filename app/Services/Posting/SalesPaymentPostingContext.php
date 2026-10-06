<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\SalesInvoice;
use App\Support\Posting\PostingContext;

/** Износи на една уплата од купувач за шемата SALES_PAYMENT. */
final class SalesPaymentPostingContext
{
    public static function build(SalesInvoice $invoice, string $amountMkd, string $amountForeign, bool $cash, string $label, Account $receivable): PostingContext
    {
        return new PostingContext(
            totals: ['ИЗНОС' => $amountMkd],
            foreignTotals: ['ИЗНОС' => $amountForeign],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => false],
            partnerId: $invoice->partner_id,
            documentLabel: $label,
            foreign: $invoice->isForeignCurrency()
                ? ['currency_code' => $invoice->currency, 'exchange_rate' => (string) $invoice->exchange_rate]
                : null,
            invoiceAccount: $receivable,
        );
    }
}
