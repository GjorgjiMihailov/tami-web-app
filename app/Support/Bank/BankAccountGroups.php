<?php

namespace App\Support\Bank;

use App\Models\BankStatement;
use App\Models\CompanyBankAccount;
use App\Models\JournalGroup;
use App\Support\JournalGroupCodes;

/**
 * Групата на налозите на изводите: денарските во 10 (заедно со плаќањата од
 * основната сметка), девизните во 11 — по вид на извод, не по банкарска сметка.
 * Сите пари се на конто 1000; сметките се разликуваат само по самиот извод.
 */
class BankAccountGroups
{
    public static function groupFor(BankStatement $statement): JournalGroup
    {
        // Сметката останува запишана во профилот на фирмата (како и порано).
        $account = CompanyBankAccount::firstOrCreate(
            ['company_id' => $statement->company_id, 'account_number' => $statement->account],
            ['bank_name' => $statement->bank],
        );

        $code = $statement->kind->isForeign() ? JournalGroupCodes::FOREIGN_BANK : JournalGroupCodes::DENAR_BANK;
        $group = JournalGroupCodes::group($statement->company, $code);

        if ($account->journal_group_id === null) {
            $account->update(['journal_group_id' => $group->id]);
        }

        return $group;
    }
}
