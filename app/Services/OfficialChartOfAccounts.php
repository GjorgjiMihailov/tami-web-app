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

    /**
     * Отпечаток на предложениот план. Фирма што го има овој отпечаток е
     * ажурирана; фирма со друг (или без) гледа понуда да го преземе планот.
     */
    public static function version(): string
    {
        return sha1_file(base_path('docs/reference/official-chart-of-accounts.json'));
    }

    public static function isCurrent(Company $company): bool
    {
        return $company->chart_version === self::version();
    }

    /**
     * Што би направило преземањето: колку конта фалат и колку постојни се
     * разликуваат (назив, ниво, родител, страна). Ништо не запишува.
     *
     * @return array{added: int, changed: int}
     */
    public static function preview(Company $company): array
    {
        $existing = Account::where('company_id', $company->id)
            ->get(['code', 'name', 'level', 'parent_code', 'must_debit', 'must_credit'])
            ->keyBy('code');

        $added = 0;
        $changed = 0;

        foreach (self::entries() as $entry) {
            $account = $existing->get($entry['code']);

            if ($account === null) {
                $added++;
            } elseif (
                $account->name !== $entry['name']
                || $account->level !== $entry['level']
                || $account->parent_code !== $entry['parent_code']
                || (bool) $account->must_debit !== (bool) $entry['must_debit']
                || (bool) $account->must_credit !== (bool) $entry['must_credit']
            ) {
                $changed++;
            }
        }

        return ['added' => $added, 'changed' => $changed];
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

        // Фирмата е сега на тековниот предложен план — без настани и без
        // нејзин updated_at да се мести (не е промена на профилот).
        $company->forceFill(['chart_version' => self::version()])->saveQuietly();
    }
}
