<?php

namespace Tests\Feature\Users;

use App\Livewire\CompanyModules;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyModulesScreenTest extends TestCase
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

    public function test_an_admin_switches_a_module_off_later(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CompanyModules::class, ['company' => $company])
            ->set('usesPayroll', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->uses_payroll);
        $this->assertTrue($company->fresh()->uses_finance);
    }

    public function test_switching_material_off_writes_stock_off_too(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CompanyModules::class, ['company' => $company])
            ->set('usesMaterial', false)
            ->set('usesStock', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->uses_material);
        $this->assertFalse($company->fresh()->uses_stock);
    }

    public function test_switching_a_module_back_on_returns_the_data_untouched(): void
    {
        // Исклучувањето само крие и брани — ниту еден ред не се брише.
        $company = Company::factory()->create();
        $partner = Partner::factory()->create(['company_id' => $company->id]);

        $company->update(['uses_material' => false]);
        $company->update(['uses_material' => true]);

        $this->assertDatabaseHas('partners', ['id' => $partner->id]);
    }

    public function test_an_accountant_cannot_change_the_modules(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyModules::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_an_individual_profile_shows_no_module_boxes(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyModules::class, ['company' => $company])
            ->assertDontSee('Материјално работење');
    }

    public function test_a_client_cannot_reach_the_modules_tab(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create();
        Role::findOrCreate('client');
        $client->assignRole('client');
        $client->forceFill(['company_id' => $company->id])->save();

        $this->actingAs($client)->get(route('companies.modules', $company))->assertForbidden();
    }

    public function test_an_accountant_cannot_reach_the_modules_tab(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)->get(route('companies.modules', $company))->assertForbidden();
    }
}
