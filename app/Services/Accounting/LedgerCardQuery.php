<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LedgerCardQuery
{
    public static function run(Company $company, array $filters): Collection
    {
        $accountId = $filters['account_id'] ?? null;
        $partnerId = $filters['partner_id'] ?? null;
        /** @var Carbon $from */
        $from = $filters['from'];
        /** @var Carbon $to */
        $to = $filters['to'];

        $baseQuery = fn () => JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->when($accountId, fn ($q) => $q->where('journal_entry_lines.account_id', $accountId))
            ->when($partnerId, fn ($q) => $q->where('journal_entry_lines.partner_id', $partnerId));

        $openingBalance = (clone $baseQuery())
            ->where('journal_entries.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        $lines = (clone $baseQuery())
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->with(['journalEntry', 'partner'])
            ->get(['journal_entry_lines.*']);

        $runningBalance = (float) $openingBalance;

        return $lines->map(function (JournalEntryLine $line) use (&$runningBalance) {
            $runningBalance += (float) $line->debit - (float) $line->credit;

            return [
                'date' => $line->journalEntry->entry_date,
                'description' => $line->description,
                'partner' => $line->partner?->name,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $runningBalance,
            ];
        })->values();
    }
}
