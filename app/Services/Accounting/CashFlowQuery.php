<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use App\Support\WorkingYear;

/**
 * Готовински тек за една година, за таблата на Финансии.
 *
 * „Пари“ се сите аналитички конта од група 10 (трансакциски сметки, благајни,
 * девизни сметки…). Уплата е дебит, исплата е кредит. Налозите од група 00
 * (почетна состојба) не се движење: тие се дел од „на почеток на годината“.
 *
 * Салдото пред годината е кумулативно од сите претходни налози — исто како
 * пробниот биланс. Месеците се собираат во PHP, не со функција на база: SQLite
 * (локално) и MySQL (продукција) се разликуваат во функциите за датум.
 */
class CashFlowQuery
{
    private const OPENING_GROUP = '00';

    /** @return array{opening: string, closing: string, months: array<int, array{in: string, out: string}>} */
    public static function forYear(Company $company, int $year): array
    {
        $accountIds = Account::where('company_id', $company->id)
            ->where('group', '10')
            ->analytical()
            ->pluck('id');

        $months = array_fill(1, 12, ['in' => '0.00', 'out' => '0.00']);
        $opening = '0.00';

        if ($accountIds->isEmpty()) {
            return ['opening' => $opening, 'closing' => $opening, 'months' => $months];
        }

        $start = WorkingYear::startOf($year);
        $end = WorkingYear::endOf($year);

        $lines = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->leftJoin('journal_groups', 'journal_groups.id', '=', 'journal_entries.journal_group_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereDate('journal_entries.entry_date', '<=', $end)
            ->get([
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entries.entry_date',
                'journal_groups.code as group_code',
            ]);

        foreach ($lines as $line) {
            $date = (string) $line->entry_date;
            $net = bcsub((string) $line->debit, (string) $line->credit, 2);
            $before = substr($date, 0, 10) < $start;

            if ($before || $line->group_code === self::OPENING_GROUP) {
                $opening = bcadd($opening, $net, 2);

                continue;
            }

            $month = (int) substr($date, 5, 2);
            $months[$month]['in'] = bcadd($months[$month]['in'], (string) $line->debit, 2);
            $months[$month]['out'] = bcadd($months[$month]['out'], (string) $line->credit, 2);
        }

        $closing = $opening;
        foreach ($months as $month) {
            $closing = bcsub(bcadd($closing, $month['in'], 2), $month['out'], 2);
        }

        return ['opening' => $opening, 'closing' => $closing, 'months' => $months];
    }
}
