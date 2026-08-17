<?php

namespace Tests\Feature;

use App\Livewire\PayrollParameterIndex;
use App\Models\Company;
use App\Models\PayrollMonthHours;
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
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
            ->assertSee('важи од веќе постои.');
    }

    public function test_a_period_added_through_the_screen_is_itself_protected(): void
    {
        // The test above only proves the rule sees rows the migration wrote with
        // a raw insert. A row written through the model used to be stored as
        // '2027-01-01 00:00:00' on SQLite, which the rule's plain comparison
        // never matched — validation passed and the column's unique index threw
        // an uncaught QueryException, a 500 instead of the Macedonian message.
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(PayrollParameterIndex::class, ['company' => $company]);

        $fill = fn ($c) => $c->set('effectiveFrom', '2027-01-01')
            ->set('ratePension', '20.5')
            ->set('rateHealth', '7.5')
            ->set('rateInjury', '0.5')
            ->set('rateUnemployment', '0.1')
            ->set('rateTax', '10')
            ->set('personalAllowance', '11500')
            ->set('averageSalary', '72000')
            ->set('minBase', '36000')
            ->set('maxBase', '1152000')
            ->set('minimumWage', '40000');

        $fill($component)->call('addPeriod')->assertHasNoErrors();

        $fill($component)->call('addPeriod')
            ->assertHasErrors(['effectiveFrom' => 'unique'])
            ->assertSee('важи од веќе постои.');

        $this->assertSame(1, PayrollParameter::where('effective_from', 'like', '2027-01-01%')->count());
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

    public function test_an_admin_can_enter_a_months_hour_fund(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2027)
            ->set('fundMonth', 1)
            ->set('fundHours', 176)
            ->call('saveMonthHours')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payroll_month_hours', [
            'year' => 2027, 'month' => 1, 'hours' => 176,
        ]);
    }

    /**
     * Backspacing the year to retype it sends an empty string on every keystroke,
     * because fundYear is bound with wire:model.live. That is safe here and this
     * test exists to keep it safe: Livewire's IntSynth::hydrateFromType() turns ''
     * into null before assignment, and the property is declared `?int`, so null is
     * a legal value and render() queries `where('year', null)` — an empty result,
     * not a crash.
     *
     * It would stop being safe if someone narrowed the property to a non-nullable
     * `int`. Livewire cannot assign null to that, catches the TypeError and unsets
     * the property, and render() then reads an uninitialized typed property, which
     * is fatal. This test is what would catch that change.
     */
    public function test_clearing_the_year_does_not_break_the_screen(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', '')
            ->assertSee('Фонд на часови по месец');
    }

    public function test_entering_the_same_month_twice_updates_it(): void
    {
        $company = Company::factory()->create();
        $this->admin();
        PayrollMonthHours::create(['year' => 2027, 'month' => 1, 'hours' => 176]);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2027)
            ->set('fundMonth', 1)
            ->set('fundHours', 168)
            ->call('saveMonthHours')
            ->assertHasNoErrors();

        $this->assertSame(168, PayrollMonthHours::forMonth(2027, 1)->hours);
        $this->assertSame(1, PayrollMonthHours::where('year', 2027)->count());
    }

    public function test_it_warns_when_an_entered_fund_does_not_match_the_calendar(): void
    {
        $company = Company::factory()->create();
        $this->admin();
        // August 2026 has 21 working days, so 168 is right and 160 is not.
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 160]);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2026)
            ->assertSee('21 × 8 = 168 часа според календарот');
    }

    public function test_it_stays_quiet_when_the_fund_matches(): void
    {
        $company = Company::factory()->create();
        $this->admin();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2026)
            ->assertDontSee('според календарот');
    }
}
