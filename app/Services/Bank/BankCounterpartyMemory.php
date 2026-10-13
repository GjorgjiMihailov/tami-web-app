<?php

namespace App\Services\Bank;

use App\Models\BankCounterparty;
use App\Models\BankStatement;
use App\Support\Bank\AccountNumber;
use App\Support\Bank\LineKind;

/**
 * Го памти она што сметководителот го определил при книжење: по сметка на
 * другата страна — партнер и конто. Следниот увоз од таа сметка доаѓа
 * предпополнет.
 *
 * Плаќање на фактура го памти само партнерот: истото лице може да е и
 * заемодавач и купувач, па уплата на фактура не смее да го пребрише контото
 * за позајмица.
 */
class BankCounterpartyMemory
{
    public function remember(BankStatement $statement): void
    {
        $statement->loadMissing('lines.salesInvoice', 'lines.purchaseInvoice');

        foreach ($statement->lines as $line) {
            $key = AccountNumber::key((string) $line->counterparty_account);

            if ($key === '') {
                continue;
            }

            $record = BankCounterparty::firstOrNew(['company_id' => $statement->company_id, 'account_key' => $key]);
            $record->name_seen = $line->counterparty_name ?? $record->name_seen;
            $record->last_used_at = now();

            if ($line->kind === LineKind::ACCOUNT && $line->account_id !== null) {
                $record->account_id = $line->account_id;

                if ($line->partner_id !== null) {
                    $record->partner_id = $line->partner_id;
                }
            } elseif ($line->kind === LineKind::INVOICE_PAYMENT) {
                $partnerId = $line->partner_id ?? $line->salesInvoice?->partner_id ?? $line->purchaseInvoice?->partner_id;

                if ($partnerId !== null) {
                    $record->partner_id = $partnerId;
                }
            }

            $record->save();
        }
    }
}
