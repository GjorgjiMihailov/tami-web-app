<?php

namespace App\Support\Bank;

use App\Models\BankStatement;
use App\Models\CompanyBankAccount;
use App\Models\JournalGroup;
use RuntimeException;

/**
 * Секоја банкарска сметка добива своја група на налози: 10, 11, 12…
 * Сите пари се на конто 1000; сметките се разликуваат само по групата.
 */
class BankAccountGroups
{
    public static function groupFor(BankStatement $statement): JournalGroup
    {
        $account = CompanyBankAccount::firstOrCreate(
            ['company_id' => $statement->company_id, 'account_number' => $statement->account],
            ['bank_name' => $statement->bank],
        );

        if ($account->journal_group_id !== null) {
            return JournalGroup::findOrFail($account->journal_group_id);
        }

        $group = JournalGroup::create([
            'company_id' => $statement->company_id,
            'code' => self::nextCode($statement->company_id),
            'name' => "Извод — {$statement->bank} {$statement->account}",
            'sort_order' => 10,
        ]);

        $account->update(['journal_group_id' => $group->id]);

        return $group;
    }

    private static function nextCode(int $companyId): string
    {
        $taken = JournalGroup::where('company_id', $companyId)->pluck('code')->all();

        // 00 е за почетна состојба и 99 за автоматските налози; кодот е два знака.
        for ($n = 10; $n <= 98; $n++) {
            if (! in_array((string) $n, $taken, true)) {
                return (string) $n;
            }
        }

        throw new RuntimeException('Нема слободна група за налози на изводи.');
    }
}
