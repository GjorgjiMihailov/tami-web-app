<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\SalesInvoice;

/**
 * На која сметка е отворено побарувањето на фактурата. Уплатата го затвора
 * ТАМУ — така стара фактура книжена на 120 пред шемите се затвора на 120, а
 * нова на 1200, без поделено салдо меѓу две конта.
 */
class PostedInvoiceAccounts
{
    public static function receivable(SalesInvoice $invoice): Account
    {
        $entry = $invoice->journalEntry()->with('lines.account')->first();

        $line = $entry?->lines
            ->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0 && $l->partner_id === $invoice->partner_id);

        if ($line?->account !== null) {
            return $line->account;
        }

        return Account::where('company_id', $invoice->company_id)->analytical()->where('code', '1200')->firstOrFail();
    }
}
