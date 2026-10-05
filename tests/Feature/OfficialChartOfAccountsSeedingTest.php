<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Services\OfficialChartOfAccounts;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficialChartOfAccountsSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_company_seeds_the_full_official_chart_of_accounts(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(count(OfficialChartOfAccounts::entries()), Account::where('company_id', $company->id)->count());
    }

    public function test_seeded_subgroups_are_synthetic_and_active_by_default(): void
    {
        $company = Company::factory()->create();

        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->assertNotNull($account);
        $this->assertSame('Побарувања од купувачи во земјата', $account->name);
        $this->assertSame('subgroup', $account->level);
        $this->assertFalse($account->is_analytical);
        $this->assertTrue($account->is_active);
        $this->assertSame('1', $account->class);
        $this->assertSame('12', $account->group);
        $this->assertSame('12', $account->parent_code);
    }

    public function test_four_to_six_digit_accounts_are_official_analytical_accounts_with_their_parent(): void
    {
        $company = Company::factory()->create();

        $account = Account::where('company_id', $company->id)->where('code', '1201')->first();
        $this->assertSame('account', $account->level);
        $this->assertTrue($account->is_analytical);
        $this->assertSame('120', $account->parent_code);

        $deep = Account::where('company_id', $company->id)->where('code', '10250')->first();
        $this->assertSame('1025', $deep->parent_code);
    }

    public function test_side_rules_are_carried_over_from_the_chart(): void
    {
        $company = Company::factory()->create();

        $concessions = Account::where('company_id', $company->id)->where('code', '0020')->first();
        $this->assertTrue($concessions->must_debit);
        $this->assertFalse($concessions->must_credit);

        $material = Account::where('company_id', $company->id)->where('code', '4000')->first();
        $this->assertTrue($material->must_credit);
        $this->assertFalse($material->must_debit);

        $plain = Account::where('company_id', $company->id)->where('code', '1000')->first();
        $this->assertFalse($plain->must_debit);
        $this->assertFalse($plain->must_credit);
    }

    public function test_every_account_the_automatic_postings_write_to_exists_and_can_carry_an_entry(): void
    {
        $company = Company::factory()->create();

        // Sales/purchase invoices, payments, payroll and the import landed-cost
        // posting all write to these 3-digit accounts by code.
        $codes = ['100', '102', '120', '130', '220', '230', '234', '235', '240', '249', '421', '660', '701', '740'];

        foreach ($codes as $code) {
            $account = Account::where('company_id', $company->id)->where('code', $code)->postable()->first();
            $this->assertNotNull($account, "Auto-posting account {$code} is missing or is a heading.");
            $this->assertTrue($account->is_active);
        }
    }

    public function test_the_chart_file_is_consistent(): void
    {
        $entries = OfficialChartOfAccounts::entries();
        $codes = array_flip(array_column($entries, 'code'));
        $levels = [1 => 'class', 2 => 'group', 3 => 'subgroup'];
        $problems = [];

        if (count($codes) !== count($entries)) {
            $problems[] = 'duplicate account codes';
        }

        foreach ($entries as $entry) {
            $code = $entry['code'];
            if (trim($entry['name']) === '' || mb_strlen($entry['name']) > 255) {
                $problems[] = "{$code}: empty or too long name";
            }
            if (($levels[strlen($code)] ?? 'account') !== $entry['level']) {
                $problems[] = "{$code}: wrong level {$entry['level']}";
            }
            if ($entry['must_debit'] && $entry['must_credit']) {
                $problems[] = "{$code}: both з.д. and з.п.";
            }
            if ($entry['parent_code'] !== null
                && (! isset($codes[$entry['parent_code']]) || ! str_starts_with($code, $entry['parent_code']))) {
                $problems[] = "{$code}: bad parent {$entry['parent_code']}";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_postable_scope_leaves_out_class_and_group_headings(): void
    {
        $company = Company::factory()->create();

        $levels = Account::where('company_id', $company->id)->postable()->pluck('level')->unique()->sort()->values()->all();

        $this->assertSame(['account', 'subgroup'], $levels);
    }

    public function test_two_companies_each_get_their_own_independent_copy(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        Account::where('company_id', $companyA->id)->where('code', '120')->first()
            ->update(['is_active' => false]);

        $this->assertFalse(Account::where('company_id', $companyA->id)->where('code', '120')->first()->is_active);
        $this->assertTrue(Account::where('company_id', $companyB->id)->where('code', '120')->first()->is_active);
    }

    public function test_sync_updates_names_adds_missing_accounts_and_keeps_everything_else(): void
    {
        $company = Company::factory()->create();
        $total = Account::where('company_id', $company->id)->count();

        // An old name, a deactivated account, a missing official account and
        // one the accountant added on their own.
        Account::where('company_id', $company->id)->where('code', '1201')->update(['name' => 'Старо име']);
        Account::where('company_id', $company->id)->where('code', '1200')->update(['is_active' => false]);
        Account::where('company_id', $company->id)->where('code', '1209')->delete();
        Account::factory()->for($company)->create(['code' => '1209991', 'name' => 'Мое конто', 'parent_code' => '1209', 'is_analytical' => true]);

        OfficialChartOfAccounts::syncForCompany($company);

        $accounts = Account::where('company_id', $company->id);
        $this->assertSame($total + 1, $accounts->count());
        $this->assertSame('Побарувања од купувачи од продажба на добра (стоки) во земјата', Account::where('company_id', $company->id)->where('code', '1201')->value('name'));
        $this->assertFalse((bool) Account::where('company_id', $company->id)->where('code', '1200')->value('is_active'));
        $this->assertTrue(Account::where('company_id', $company->id)->where('code', '1209')->exists());
        $this->assertTrue(Account::where('company_id', $company->id)->where('code', '1209991')->exists());
    }

    public function test_sync_command_runs_for_every_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Account::whereIn('company_id', [$a->id, $b->id])->where('code', '1000')->delete();

        $this->artisan('accounts:sync-official')->assertSuccessful();

        $this->assertTrue(Account::where('company_id', $a->id)->where('code', '1000')->exists());
        $this->assertTrue(Account::where('company_id', $b->id)->where('code', '1000')->exists());
    }

    public function test_seeding_rolls_back_completely_when_a_batch_fails_partway(): void
    {
        $company = Company::factory()->create();
        Account::where('company_id', $company->id)->delete();

        // Let the first batch go through, then make the second one fail.
        $inserts = 0;
        DB::beforeExecuting(function (string $query) use (&$inserts) {
            if (str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'accounts')) {
                if (++$inserts === 2) {
                    throw new \RuntimeException('simulated failure in batch 2');
                }
            }
        });

        try {
            OfficialChartOfAccounts::seedForCompany($company);
            $this->fail('Expected the second batch to fail.');
        } catch (\RuntimeException|QueryException $e) {
            // Expected.
        }

        $this->assertGreaterThanOrEqual(2, $inserts);
        $this->assertSame(0, Account::where('company_id', $company->id)->count());
    }
}
