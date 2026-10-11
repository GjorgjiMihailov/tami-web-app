<?php

namespace App\Services\Posting;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Support\JournalGroupCodes;
use Illuminate\Support\Facades\DB;

/**
 * Старите автоматски налози се преместуваат во групите по мапата на
 * сопственикот (види JournalGroupCodes):
 *
 *  - плата (и нејзините сторна) од 99 → 50;
 *  - банкарски плаќања на фактури („Payment for …“ со ставка на 100/1000) од 99 → 10;
 *  - книжени изводи: денарски → 10, девизни → 11 (порано по банкарска сметка).
 *
 * Се менува само групата и редниот број на налогот (следниот слободен во
 * новата група за годината); ставките не се допираат. Безбедно за повторно
 * извршување.
 *
 * @phpstan-type Counts array{payroll: int, payments: int, statements: int}
 */
class JournalGroupRelocator
{
    /** @return array{payroll: int, payments: int, statements: int} */
    public function run(): array
    {
        $counts = ['payroll' => 0, 'payments' => 0, 'statements' => 0];

        Company::query()->each(function (Company $company) use (&$counts) {
            DB::transaction(function () use ($company, &$counts) {
                $counts['payroll'] += $this->move($company, $this->payrollEntryIds($company), JournalGroupCodes::PAYROLL);
                $counts['payments'] += $this->move($company, $this->bankPaymentEntryIds($company), JournalGroupCodes::DENAR_BANK);

                foreach ([[JournalGroupCodes::DENAR_BANK, 'denar'], [JournalGroupCodes::FOREIGN_BANK, 'foreign']] as [$code, $kind]) {
                    $counts['statements'] += $this->move($company, $this->statementEntryIds($company, $kind, $code), $code);
                }
            });
        });

        return $counts;
    }

    /** @return list<int> */
    private function payrollEntryIds(Company $company): array
    {
        $legacy = $this->legacyGroupId($company);

        if ($legacy === null) {
            return [];
        }

        $runEntries = DB::table('payroll_runs')
            ->where('company_id', $company->id)
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id');

        return JournalEntry::where('company_id', $company->id)
            ->where('journal_group_id', $legacy)
            ->where(fn ($q) => $q->whereIn('id', $runEntries)->orWhere('description', 'like', 'Сторно: Плата %'))
            ->pluck('id')->all();
    }

    /** @return list<int> */
    private function bankPaymentEntryIds(Company $company): array
    {
        $legacy = $this->legacyGroupId($company);

        if ($legacy === null) {
            return [];
        }

        return JournalEntry::where('company_id', $company->id)
            ->where('journal_group_id', $legacy)
            ->where(fn ($q) => $q->where('description', 'like', 'Payment for invoice %')
                ->orWhere('description', 'like', 'Payment for purchase bill %'))
            ->whereHas('lines.account', fn ($account) => $account->whereIn('code', ['100', '1000']))
            ->pluck('id')->all();
    }

    /** @return list<int> */
    private function statementEntryIds(Company $company, string $kind, string $code): array
    {
        return BankStatement::where('company_id', $company->id)
            ->where('kind', $kind)
            ->where('status', BankStatement::STATUS_BOOKED)
            ->whereNotNull('journal_entry_id')
            ->whereHas('journalEntry.journalGroup', fn ($group) => $group->where('code', '!=', $code))
            ->pluck('journal_entry_id')->all();
    }

    private function legacyGroupId(Company $company): ?int
    {
        return DB::table('journal_groups')
            ->where('company_id', $company->id)
            ->where('code', JournalGroupCodes::OTHER_AUTOMATIC)
            ->value('id');
    }

    /** @param  list<int>  $entryIds */
    private function move(Company $company, array $entryIds, string $code): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $target = JournalGroupCodes::group($company, $code);
        $moved = 0;

        $entries = JournalEntry::whereIn('id', $entryIds)
            ->where('journal_group_id', '!=', $target->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $next = JournalEntry::where('company_id', $company->id)
                ->where('journal_group_id', $target->id)
                ->where('fiscal_year', $entry->fiscal_year)
                ->max('entry_number');

            $entry->journal_group_id = $target->id;
            $entry->entry_number = ($next ?? 0) + 1;
            $entry->save();
            $moved++;
        }

        return $moved;
    }
}
