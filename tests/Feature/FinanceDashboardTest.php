<?php

namespace Tests\Feature;

use App\Livewire\Apps\FinanceDashboard;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\CompanyType;
use App\Support\Menu;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    private function dashboard(Company $company, ?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->admin())->test(FinanceDashboard::class, ['company' => $company]);
    }

    public function test_the_board_lives_on_the_finansii_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith('http://'.PortalApp::FINANSII->domain(), route('finansii.dashboard', $company));
    }

    public function test_it_opens_and_names_the_company_without_uncompiled_components(): void
    {
        $company = Company::factory()->create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->actingAs($this->admin())
            ->get(route('finansii.dashboard', $company))
            ->assertOk()
            ->assertSee('ТЕСТ ДООЕЛ')
            ->assertSee('Побарувања')
            ->assertSee('Обврски')
            ->assertSee('Готовински тек')
            ->assertDontSee('<x-', false);
    }

    public function test_a_stranger_may_not_open_someone_elses_board(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        $this->actingAs($this->client($mine))->get(route('finansii.dashboard', $theirs))->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('finansii.dashboard', Company::factory()->create()))->assertRedirect();
    }

    public function test_an_individual_has_no_board_and_a_switched_off_module_closes_it(): void
    {
        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $noFinance = Company::factory()->create(['uses_finance' => false]);

        $this->actingAs($this->admin())->get(route('finansii.dashboard', $individual))->assertStatus(403);
        $this->actingAs($this->admin())->get(route('finansii.dashboard', $noFinance))->assertStatus(403);
    }

    public function test_finansii_is_entered_through_its_board_for_a_legal_entity_only(): void
    {
        $legal = Company::factory()->create();
        $admin = $this->admin();

        $this->assertSame(route('finansii.dashboard', $legal), Menu::landingUrl($admin, $legal, PortalApp::FINANSII));

        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->assertSame(
            Menu::firstUrl($admin, $individual, PortalApp::FINANSII),
            Menu::landingUrl($admin, $individual, PortalApp::FINANSII)
        );
    }

    public function test_receivables_and_payables_show_unpaid_totals_and_the_split(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-01-01', 'due_date' => now()->subDays(5)->toDateString(),
        ]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '400.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);

        $html = $this->dashboard($company)->html();

        $this->assertStringContainsString('400,00', $html);
        $this->assertStringContainsString('Задоцнето', $html);
        $this->assertStringContainsString('width: 100%', $html);
    }

    public function test_the_bar_shares_are_empty_when_nothing_is_unpaid(): void
    {
        $this->assertSame(['current' => 0, 'overdue' => 0], FinanceDashboard::shares(['total' => '0.00', 'current' => '0.00', 'overdue' => '0.00']));
        $this->assertSame(['current' => 25, 'overdue' => 75], FinanceDashboard::shares(['total' => '400.00', 'current' => '100.00', 'overdue' => '300.00']));
    }

    public function test_the_new_menu_offers_the_right_actions_to_an_admin(): void
    {
        $company = Company::factory()->create();

        $component = $this->dashboard($company)->instance();

        $receivable = array_column($component->receivableActions(), 'label');
        $payable = array_column($component->payableActions(), 'label');

        $this->assertSame(['Нова фактура', 'Нова профактура', 'Внеси уплата (извод)'], $receivable);
        $this->assertSame(['Нова влезна фактура', 'Внеси исплата (извод)'], $payable);
    }

    public function test_the_new_menu_hides_invoices_when_material_is_off_and_banking_for_a_client(): void
    {
        $company = Company::factory()->create(['uses_material' => false]);

        $admin = $this->dashboard($company)->instance();
        $this->assertSame(['Внеси уплата (извод)'], array_column($admin->receivableActions(), 'label'));

        $own = Company::factory()->create();
        $asClient = $this->dashboard($own, $this->client($own))->instance();
        $this->assertNotContains('Внеси уплата (извод)', array_column($asClient->receivableActions(), 'label'));
        $this->assertNotContains('Внеси исплата (извод)', array_column($asClient->payableActions(), 'label'));
    }

    public function test_cash_flow_is_shown_to_the_office_and_hidden_from_a_client(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::create(['company_id' => $company->id, 'code' => '10', 'name' => 'G', 'sort_order' => 1]);
        $entry = JournalEntry::create([
            'company_id' => $company->id, 'journal_group_id' => $group->id,
            'entry_date' => now()->year.'-02-10', 'description' => 't', 'created_by' => User::factory()->create()->id,
        ]);
        $entry->lines()->create([
            'account_id' => Account::where('company_id', $company->id)->where('code', '1000')->value('id'),
            'line_date' => now()->year.'-02-10', 'debit' => '1234.00', 'credit' => '0',
        ]);

        $this->assertStringContainsString('1.234,00', $this->dashboard($company)->html());

        $clientHtml = $this->dashboard($company, $this->client($company))->html();

        $this->assertStringNotContainsString('Готовински тек', $clientHtml);
        $this->assertStringNotContainsString('1.234,00', $clientHtml);
    }
}
