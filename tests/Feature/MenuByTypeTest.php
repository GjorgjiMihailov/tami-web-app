<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuByTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function groupsFor(CompanyType $type): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['type' => $type]);

        return array_column(Menu::for($admin, $company), 'label');
    }

    public function test_a_legal_entity_sees_the_full_menu(): void
    {
        $groups = $this->groupsFor(CompanyType::LEGAL);

        $this->assertContains('ФИНАНСИИ', $groups);
        $this->assertContains('ПРОДАЖБА', $groups);
        $this->assertContains('ТРОШОЦИ', $groups);
        $this->assertContains('ЗАЛИХА', $groups);
        $this->assertContains('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', $groups);
    }

    public function test_an_individual_sees_neither_bookkeeping_nor_stock_nor_payroll(): void
    {
        $groups = $this->groupsFor(CompanyType::INDIVIDUAL);

        $this->assertNotContains('ФИНАНСИИ', $groups);
        $this->assertNotContains('ТРОШОЦИ', $groups);
        $this->assertNotContains('ЗАЛИХА', $groups);
        $this->assertNotContains('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', $groups);
    }

    public function test_an_individual_sees_sales_bank_documents_and_filings(): void
    {
        $groups = $this->groupsFor(CompanyType::INDIVIDUAL);

        $this->assertContains('ПРОДАЖБА', $groups);
        $this->assertContains('БАНКАРСКИ ДОКУМЕНТИ', $groups);
        $this->assertContains('ПРИЈАВИ', $groups);
        $this->assertContains('ПОСТАВКИ', $groups);
    }

    /**
     * Влезните фактури беа во ПРОДАЖБА; барањето беше да се одвојат. Овој тест
     * паѓа ако некој ги врати назад.
     */
    public function test_purchase_invoices_moved_out_of_sales(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        $tree = collect(Menu::for($admin, $company))->keyBy('label');

        $sales = array_column($tree['ПРОДАЖБА']['items'], 'label');
        $costs = array_column($tree['ТРОШОЦИ']['items'], 'label');

        $this->assertNotContains('Влезни фактури', $sales);
        $this->assertContains('Влезни фактури', $costs);
    }
}
