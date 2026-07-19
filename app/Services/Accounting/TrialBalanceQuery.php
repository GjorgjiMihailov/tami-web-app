<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrialBalanceQuery
{
    public static function run(Company $company, string $groupBy, Carbon $from, Carbon $to): Collection
    {
        // NOTE: grouping is done in PHP rather than SQL GROUP BY so that the
        // 'account_partner' composite key doesn't need a cross-database string
        // concatenation expression (MySQL's CONCAT() vs SQLite's || operator).
        // Data volumes here are small (one accounting firm's clients), so this
        // is not a performance concern.
        $keyFor = match ($groupBy) {
            'account' => fn (JournalEntryLine $line) => $line->account->code,
            'synthetic' => fn (JournalEntryLine $line) => substr($line->account->code, 0, 3),
            'partner' => fn (JournalEntryLine $line) => $line->partner_id ? (string) $line->partner_id : null,
            'account_partner' => fn (JournalEntryLine $line) => $line->partner_id
                ? $line->account->code.'::'.$line->partner_id
                : null,
            default => throw new \InvalidArgumentException("Unknown grouping [{$groupBy}]."),
        };

        $labelFor = match ($groupBy) {
            'account', 'synthetic' => fn (JournalEntryLine $line) => $line->account->name,
            'partner', 'account_partner' => fn (JournalEntryLine $line) => $line->partner?->name,
        };

        $baseQuery = fn () => JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->with(['account', 'partner']);

        $openingLines = (clone $baseQuery())
            ->where('journal_entries.entry_date', '<', $from->toDateString())
            ->get(['journal_entry_lines.*']);

        $movementLines = (clone $baseQuery())
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->get(['journal_entry_lines.*']);

        $openingTotals = self::totalsByKey($openingLines, $keyFor);
        $movementTotals = self::totalsByKey($movementLines, $keyFor);

        $keys = $openingTotals->keys()->merge($movementTotals->keys())->unique()->filter();

        $labels = $groupBy === 'synthetic'
            ? Account::where('company_id', $company->id)->where('is_analytical', false)->pluck('name', 'code')
            : self::labelsByKey($movementLines->isNotEmpty() ? $movementLines : $openingLines, $keyFor, $labelFor);

        return $keys->map(function ($key) use ($openingTotals, $movementTotals, $labels) {
            $opening = $openingTotals->get($key, ['debit' => 0.0, 'credit' => 0.0]);
            $movement = $movementTotals->get($key, ['debit' => 0.0, 'credit' => 0.0]);
            $openingBalance = $opening['debit'] - $opening['credit'];
            $movementDebit = $movement['debit'];
            $movementCredit = $movement['credit'];

            return [
                'key' => $key,
                'label' => $labels->get($key, $key),
                'opening_balance' => $openingBalance,
                'movement_debit' => $movementDebit,
                'movement_credit' => $movementCredit,
                'closing_balance' => $openingBalance + $movementDebit - $movementCredit,
            ];
        })->sortBy('key')->values();
    }

    private static function totalsByKey(Collection $lines, \Closure $keyFor): Collection
    {
        return $lines
            ->filter(fn (JournalEntryLine $line) => $keyFor($line) !== null)
            ->groupBy($keyFor)
            ->map(fn (Collection $group) => [
                'debit' => (float) $group->sum('debit'),
                'credit' => (float) $group->sum('credit'),
            ]);
    }

    private static function labelsByKey(Collection $lines, \Closure $keyFor, \Closure $labelFor): Collection
    {
        return $lines
            ->filter(fn (JournalEntryLine $line) => $keyFor($line) !== null)
            ->groupBy($keyFor)
            ->map(fn (Collection $group) => $labelFor($group->first()));
    }
}
