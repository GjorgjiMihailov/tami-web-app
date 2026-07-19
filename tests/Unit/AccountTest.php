<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_and_group_are_derived_from_code_on_save(): void
    {
        $company = Company::factory()->create();

        $account = Account::create([
            'company_id' => $company->id,
            'code' => '1200',
            'name' => 'Побарувања од купувачи во земјата',
            'parent_code' => '120',
            'is_analytical' => true,
            'is_active' => true,
        ]);

        $this->assertSame('1', $account->class);
        $this->assertSame('12', $account->group);
    }

    public function test_account_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $account = Account::factory()->for($company)->create();

        $this->assertTrue($account->company->is($company));
    }

    public function test_code_is_unique_per_company(): void
    {
        // Uses a code outside the official chart of accounts (auto-seeded
        // whenever a Company is created, see OfficialChartOfAccounts) so
        // this test's own uniqueness violation isn't masked by seed data.
        $company = Company::factory()->create();
        Account::factory()->for($company)->create(['code' => '999999']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Account::factory()->for($company)->create(['code' => '999999']);
    }
}
