<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class OfficialChartOfAccounts
{
    private const CHUNK = 250;

    /**
     * The official chart from docs/reference/official-chart-of-accounts.json,
     * built from the owner's spreadsheet by docs/reference/build-official-chart.py.
     *
     * @return array<int, array{code: string, name: string, level: string, parent_code: ?string, must_debit: bool, must_credit: bool}>
     */
    public static function entries(): array
    {
        return json_decode(
            File::get(base_path('docs/reference/official-chart-of-accounts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public static function seedForCompany(Company $company): void
    {
        self::syncForCompany($company);
    }

    /**
     * Brings one company's accounts in line with the official chart: updates
     * name, level, parent and side rules of accounts that exist, adds the ones
     * that are missing, and never deletes anything or touches is_active.
     * Safe to run again and again.
     */
    public static function syncForCompany(Company $company): void
    {
        $now = now();

        $rows = array_map(fn (array $entry) => [
            'company_id' => $company->id,
            'code' => $entry['code'],
            'name' => $entry['name'],
            'level' => $entry['level'],
            'class' => substr($entry['code'], 0, 1),
            'group' => substr($entry['code'], 0, 2),
            'parent_code' => $entry['parent_code'],
            'is_analytical' => $entry['level'] === Account::LEVEL_ACCOUNT,
            'must_debit' => $entry['must_debit'],
            'must_credit' => $entry['must_credit'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::entries());

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                Account::upsert($chunk, ['company_id', 'code'], [
                    'name', 'level', 'parent_code', 'is_analytical', 'must_debit', 'must_credit', 'updated_at',
                ]);
            }
        });
    }
}
