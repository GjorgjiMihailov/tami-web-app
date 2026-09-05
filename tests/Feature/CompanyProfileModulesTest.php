<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileModulesTest extends TestCase
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
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editUsesPayroll', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->uses_payroll);
        $this->assertTrue($company->fresh()->uses_finance);
    }

    public function test_switching_material_off_writes_stock_off_too(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editUsesMaterial', false)
            ->set('editUsesStock', true)
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
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }

    public function test_an_individual_profile_shows_no_module_boxes(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('Материјално работење');
    }
}
