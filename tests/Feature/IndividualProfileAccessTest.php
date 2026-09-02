<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IndividualProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public static function forbiddenRoutes(): array
    {
        return [
            'контен план' => ['accounting.accounts.index'],
            'налози' => ['accounting.journal-groups.index'],
            'извештаи' => ['reports.index'],
            'магацини' => ['inventory.warehouses.index'],
            'артикли' => ['inventory.items.index'],
            'вработени' => ['employees.index'],
            'плати' => ['payroll-runs.index'],
            'параметри за плата' => ['payroll-parameters.index'],
            'влезни фактури' => ['purchase-invoices.index'],
            'документи' => ['documents.index'],
        ];
    }

    #[DataProvider('forbiddenRoutes')]
    public function test_an_individual_profile_refuses_a_screen_that_does_not_apply(string $route): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertForbidden();
    }

    #[DataProvider('forbiddenRoutes')]
    public function test_a_legal_profile_still_reaches_the_same_screen(string $route): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertOk();
    }

    public function test_an_individual_profile_still_reaches_its_own_screens(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('sales-invoices.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('partners.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('companies.profile', $company))->assertOk();
    }
}
