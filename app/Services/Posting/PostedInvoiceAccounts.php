<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\PurchaseInvoice;
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
        $line = app(InvoiceJournal::class)->linesOf($invoice)
            ->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0 && $l->partner_id === $invoice->partner_id);

        if ($line?->account !== null) {
            return $line->account;
        }

        return Account::where('company_id', $invoice->company_id)->analytical()->where('code', '1200')->firstOrFail();
    }

    /**
     * Сите сметки на кои фактурата го отворила побарувањето (1210 и 1211 на
     * мешана девизна фактура), со денарскиот износ и износот во валута.
     *
     * @return list<array{account: Account, debit: string, foreign: string}>
     */
    public static function receivables(SalesInvoice $invoice): array
    {
        $byAccount = [];

        foreach (app(InvoiceJournal::class)->linesOf($invoice) as $line) {
            if (bccomp((string) $line->debit, '0', 2) <= 0 || $line->partner_id !== $invoice->partner_id || $line->account === null) {
                continue;
            }

            $byAccount[$line->account->id] ??= ['account' => $line->account, 'debit' => '0.00', 'foreign' => '0.00'];
            $byAccount[$line->account->id]['debit'] = bcadd($byAccount[$line->account->id]['debit'], (string) $line->debit, 2);
            $byAccount[$line->account->id]['foreign'] = bcadd($byAccount[$line->account->id]['foreign'], (string) ($line->foreign_amount ?? '0'), 2);
        }

        return array_values($byAccount);
    }

    /** Сметката на којашто влезната фактура ја отворила обврската кон добавувачот. */
    public static function payable(PurchaseInvoice $invoice): Account
    {
        $line = app(InvoiceJournal::class)->linesOf($invoice)
            ->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0 && $l->partner_id === $invoice->partner_id);

        if ($line?->account !== null) {
            return $line->account;
        }

        return Account::where('company_id', $invoice->company_id)->analytical()->where('code', $invoice->is_import ? '2210' : '2200')->firstOrFail();
    }
}
