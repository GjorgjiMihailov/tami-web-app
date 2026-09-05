<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** По една адреса од секој модул, и колоната што ја затвора. */
    public static function guardedRoutes(): array
    {
        return [
            'излезни фактури' => ['sales-invoices.index', 'uses_material'],
            'влезни фактури' => ['purchase-invoices.index', 'uses_material'],
            'други трошоци' => ['other-costs.index', 'uses_material'],
            'магацини' => ['inventory.warehouses.index', 'uses_stock'],
            'состојба' => ['inventory.reports.stock-on-hand', 'uses_stock'],
            'вработени' => ['employees.index', 'uses_payroll'],
            'плати' => ['payroll-runs.index', 'uses_payroll'],
            'параметри за плата' => ['payroll-parameters.index', 'uses_payroll'],
            'контен план' => ['accounting.accounts.index', 'uses_finance'],
            'главна книга' => ['accounting.journal-groups.index', 'uses_finance'],
            'извештаи' => ['reports.index', 'uses_finance'],
            'изводи' => ['bank-statements.index', 'uses_finance'],
        ];
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_switched_off_module_refuses_its_screen(string $route, string $column): void
    {
        $company = Company::factory()->create([$column => false]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertForbidden();
    }

    #[DataProvider('guardedRoutes')]
    public function test_the_same_screen_opens_when_the_module_is_on(string $route, string $column): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertOk();
    }

    public function test_an_accountant_is_refused_too_not_only_a_client(): void
    {
        // Модулот е поставка на канцеларијата, не право на корисникот. Брана
        // што има исклучоци не е брана — ако сметководителот треба да влезе,
        // админ го враќа штиклирањето.
        $company = Company::factory()->create(['uses_payroll' => false]);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('employees.index', $company))
            ->assertForbidden();
    }

    public function test_stock_screens_close_with_material_even_when_their_own_flag_is_on(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('inventory.items.index', $company))
            ->assertForbidden();
    }

    public function test_partners_and_documents_stay_open_with_every_module_off(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => false,
            'uses_payroll' => false,
            'uses_finance' => false,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('partners.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('documents.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('companies.profile', $company))->assertOk();
    }

    public function test_an_individual_profile_is_never_closed_by_a_module(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'uses_material' => false,
        ]);

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk();
    }
}
