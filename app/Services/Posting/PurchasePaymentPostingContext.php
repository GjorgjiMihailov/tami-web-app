<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Support\Posting\PostingContext;
use App\Models\PurchaseInvoice;

/** Износи на една исплата кон добавувач за шемата PURCHASE_PAYMENT. */
final class PurchasePaymentPostingContext
{
    public static function build(PurchaseInvoice $invoice, string $amount, bool $cash, string $label, Account $payable): PostingContext
    {
        return new PostingContext(
            totals: ['ИЗНОС' => $amount],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => (bool) $invoice->is_import],
            partnerId: $invoice->partner_id,
            documentLabel: $label,
            invoiceAccount: $payable,
        );
    }
}
