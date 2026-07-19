<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_view_but_not_edit_their_own_companys_accounts(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $account = Account::factory()->for($company)->create();

        $this->assertTrue($client->can('view', $account));
        $this->assertFalse($client->can('update', $account));
    }

    public function test_client_cannot_view_another_companys_accounts(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $account = Account::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $account));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_journal_entries(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $accountant->id]);

        $this->assertTrue($accountant->can('create', JournalEntry::class));
        $this->assertTrue($accountant->can('update', $entry));
        $this->assertTrue($accountant->can('delete', $entry));
    }

    public function test_client_cannot_create_or_edit_journal_entries(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $entry = JournalEntry::factory()->for($company)->create();

        $this->assertFalse($client->can('create', JournalEntry::class));
        $this->assertFalse($client->can('update', $entry));
    }

    public function test_admin_can_manage_partners_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $partner));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_accounting_data(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);

        $account = Account::factory()->for($companyTheyDoNotManage)->create();
        $partner = Partner::factory()->for($companyTheyDoNotManage)->create();
        $entry = JournalEntry::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $account));
        $this->assertFalse($accountant->can('view', $partner));
        $this->assertFalse($accountant->can('view', $entry));
    }
}
