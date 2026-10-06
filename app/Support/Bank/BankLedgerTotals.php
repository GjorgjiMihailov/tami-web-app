<?php

namespace App\Support\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntryLine;
use App\Support\BankStatementKind;

/**
 * Контрола „вкупно“: салдото на 1000 мора да е збир на крајните состојби од
 * последниот книжен извод на секоја сметка. На 31.12. тоа се парите во банка.
 */
class BankLedgerTotals
{
    /** @return array{ledger: string, closings: string, difference: string} */
    public static function forCompany(Company $company, int $year): array
    {
        $bankId = Account::where('company_id', $company->id)->where('code', Account::BANK_CODE)->value('id');

        $ledger = JournalEntryLine::where('account_id', $bankId)
            ->whereHas('journalEntry', fn ($entry) => $entry->where('company_id', $company->id)->where('fiscal_year', $year))
            ->get(['debit', 'credit'])
            ->reduce(
                fn (string $carry, $line) => bcsub(bcadd($carry, (string) $line->debit, 2), (string) $line->credit, 2),
                '0.00'
            );

        $closings = BankStatement::where('company_id', $company->id)
            ->where('kind', BankStatementKind::DENAR)
            ->where('status', BankStatement::STATUS_BOOKED)
            ->whereYear('statement_date', $year)
            ->orderBy('number')
            ->get()
            ->groupBy('account')
            ->reduce(fn (string $carry, $group) => bcadd($carry, (string) $group->last()->closing_balance, 2), '0.00');

        return ['ledger' => $ledger, 'closings' => $closings, 'difference' => bcsub($ledger, $closings, 2)];
    }
}
