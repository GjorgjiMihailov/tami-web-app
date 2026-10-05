<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalGroup;
use App\Support\Bank\BankAccountGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_account_gets_group_10_and_the_second_gets_11(): void
    {
        $company = Company::factory()->create();
        $a = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'bank' => 'Комерцијална']);
        $b = BankStatement::factory()->for($company)->create(['account' => '200000000000002', 'bank' => 'Стопанска']);

        $this->assertSame('10', BankAccountGroups::groupFor($a)->code);
        $this->assertSame('11', BankAccountGroups::groupFor($b)->code);
    }

    public function test_the_same_account_keeps_its_group(): void
    {
        $company = Company::factory()->create();
        $first = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'number' => 1]);
        $second = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'number' => 2]);

        $this->assertSame(BankAccountGroups::groupFor($first)->id, BankAccountGroups::groupFor($second)->id);
        $this->assertSame(1, $company->bankAccounts()->count());
        $this->assertSame(1, JournalGroup::where('company_id', $company->id)->count());
    }

    public function test_a_code_already_taken_by_another_group_is_skipped(): void
    {
        $company = Company::factory()->create();
        JournalGroup::create(['company_id' => $company->id, 'code' => '10', 'name' => 'Друго', 'sort_order' => 10]);
        $statement = BankStatement::factory()->for($company)->create();

        $this->assertSame('11', BankAccountGroups::groupFor($statement)->code);
    }

    public function test_an_account_from_the_profile_is_reused_not_duplicated(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална', 'account_number' => '300000000000000']);
        $statement = BankStatement::factory()->for($company)->create(['account' => '300000000000000']);

        BankAccountGroups::groupFor($statement);

        $this->assertSame(1, $company->bankAccounts()->count());
        $this->assertNotNull($company->bankAccounts()->first()->journal_group_id);
    }
}
