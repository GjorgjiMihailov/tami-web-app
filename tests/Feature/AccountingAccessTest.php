<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public static function accountingRoutes(): array
    {
        return [
            'chart of accounts' => ['accounting.accounts.index'],
            'journal groups' => ['accounting.journal-groups.index'],
            'journal entries' => ['accounting.journal-entries.index'],
            'new journal entry' => ['accounting.journal-entries.create'],
        ];
    }

    private function internalClient(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public static function readOnlyReportRoutes(): array
    {
        return [
            'ddv04' => ['reports.ddv04'],
            'reports index' => ['reports.index'],
            'trial balance' => ['accounting.reports.trial-balance'],
            'ledger card' => ['accounting.reports.ledger-card'],
        ];
    }

    #[DataProvider('readOnlyReportRoutes')]
    public function test_an_internal_client_reaches_the_read_only_reports(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route($routeName, $company))
            ->assertOk();
    }

    #[DataProvider('readOnlyReportRoutes')]
    public function test_an_admin_and_an_accountant_still_reach_the_read_only_reports(string $routeName): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($admin)->get(route($routeName, $company))->assertOk();
        $this->actingAs($accountant)->get(route($routeName, $company))->assertOk();
    }

    public static function stillClosedAccountingRoutes(): array
    {
        return [
            'chart of accounts' => ['accounting.accounts.index'],
            'journal groups' => ['accounting.journal-groups.index'],
            'journal entries' => ['accounting.journal-entries.index'],
        ];
    }

    #[DataProvider('stillClosedAccountingRoutes')]
    public function test_an_internal_client_is_still_refused_the_books(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route($routeName, $company))
            ->assertForbidden();
    }

    #[DataProvider('readOnlyReportRoutes')]
    public function test_an_internal_client_cannot_reach_another_companys_reports(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient(Company::factory()->create()))
            ->get(route($routeName, $company))
            ->assertForbidden();
    }

    #[DataProvider('accountingRoutes')]
    public function test_a_client_is_refused_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->client($company))
            ->get(route($routeName, $company))
            ->assertForbidden();
    }

    #[DataProvider('accountingRoutes')]
    public function test_an_admin_still_reaches_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route($routeName, $company))->assertOk();
    }

    #[DataProvider('accountingRoutes')]
    public function test_an_accountant_still_reaches_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)->get(route($routeName, $company))->assertOk();
    }

    public function test_a_client_is_refused_a_journal_entry_pdf(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();

        $this->actingAs($this->client($company))
            ->get(route('accounting.journal-entries.pdf', [$company, $entry]))
            ->assertForbidden();
    }

    public function test_a_client_can_no_longer_view_an_account_or_a_journal_entry_at_the_policy_level(): void
    {
        $company = Company::factory()->create();
        $client = $this->client($company);
        $entry = JournalEntry::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->firstOrFail();

        $this->assertFalse($client->can('view', $account));
        $this->assertFalse($client->can('view', $entry));
    }

    public function test_a_client_keeps_access_to_sales_stock_and_documents(): void
    {
        $company = Company::factory()->create();
        $client = $this->client($company);

        $this->actingAs($client);

        $this->get(route('sales-invoices.index', $company))->assertOk();
        $this->get(route('purchase-invoices.index', $company))->assertOk();
        $this->get(route('inventory.items.index', $company))->assertOk();
        $this->get(route('partners.index', $company))->assertOk();
        $this->get(route('documents.index', $company))->assertOk();
        $this->get(route('companies.dashboard', $company))->assertOk();
    }
}
