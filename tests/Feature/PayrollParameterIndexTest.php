<?php

namespace Tests\Feature;

use App\Livewire\PayrollParameterIndex;
use App\Models\Company;
use App\Models\PayrollParameter;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollParameterIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_may_open_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('payroll-parameters.index', $company))
            ->assertOk()
            ->assertSee('19,9');
    }

    public function test_an_accountant_may_not_open_it(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->get(route('payroll-parameters.index', $company))
            ->assertForbidden();
    }

    public function test_a_client_may_not_open_it(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('payroll-parameters.index', $company))
            ->assertForbidden();
    }

    public function test_an_admin_can_add_a_new_period(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('effectiveFrom', '2027-01-01')
            ->set('ratePension', '20.5')
            ->set('rateHealth', '7.5')
            ->set('rateInjury', '0.5')
            ->set('rateUnemployment', '0.1')
            ->set('rateTax', '10')
            ->set('personalAllowance', '11500')
            ->set('averageSalary', '72000')
            ->set('minBase', '36000')
            ->set('maxBase', '1152000')
            ->set('minimumWage', '40000')
            ->call('addPeriod')
            ->assertHasNoErrors();

        $this->assertSame(20.5, PayrollParameter::forDate('2027-06-01')->rate_pension);
    }

    public function test_a_duplicate_effective_from_shows_a_macedonian_error_message(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // 2026-07-01 is already seeded by the payroll_parameters migration, so
        // this exercises the uniqueness rule that protects the append-only
        // design (periods are never edited, only added).
        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('effectiveFrom', '2026-07-01')
            ->set('ratePension', '20.5')
            ->set('rateHealth', '7.5')
            ->set('rateInjury', '0.5')
            ->set('rateUnemployment', '0.1')
            ->set('rateTax', '10')
            ->set('personalAllowance', '11500')
            ->set('averageSalary', '72000')
            ->set('minBase', '36000')
            ->set('maxBase', '1152000')
            ->set('minimumWage', '40000')
            ->call('addPeriod')
            ->assertHasErrors(['effectiveFrom' => 'unique'])
            ->assertSee('Важи од веќе постои.');
    }

    public function test_the_parameters_menu_entry_is_admin_only(): void
    {
        $company = Company::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $labels = fn (User $u) => collect(Menu::for($u, $company))
            ->firstWhere('key', 'settings')['items'] ?? [];

        $this->assertContains('Параметри за плата', array_column($labels($admin), 'label'));
        $this->assertNotContains('Параметри за плата', array_column($labels($accountant), 'label'));
    }
}
