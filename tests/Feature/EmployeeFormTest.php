<?php

namespace Tests\Feature;

use App\Livewire\EmployeeForm;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollParameter;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_typing_a_gross_amount_fills_in_the_net(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('gross', '38507')
            ->assertSet('net', '26046');
    }

    public function test_typing_a_net_amount_fills_in_the_gross(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('net', '26046')
            ->assertSet('gross', '38507');
    }

    public function test_typing_an_amount_before_the_first_seeded_period_shows_a_validation_error(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2025-06-30')
            ->set('gross', '38507')
            ->assertHasErrors(['salaryEffectiveFrom'])
            ->assertSee('Нема параметри за пресметка на плата за овој датум');
    }

    public function test_a_required_field_error_uses_the_macedonian_attribute_name(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->call('save')
            ->assertHasErrors(['firstName' => 'required'])
            ->assertSee('име полето е задолжително')
            ->assertDontSee('firstName полето');
    }

    public function test_a_comma_typed_as_the_decimal_separator_is_not_truncated(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('net', '45000,50')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employee_salaries', [
            'amount' => 45000.50,
            'basis' => 'net',
        ]);
    }

    public function test_it_stores_only_the_side_that_was_typed(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('net', '26046')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employee_salaries', [
            'amount' => 26046,
            'basis' => 'net',
        ]);

        $this->assertDatabaseMissing('employee_salaries', ['basis' => 'gross']);
    }

    public function test_it_rejects_an_invalid_embg(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455018')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->call('save')
            ->assertHasErrors(['embg']);
    }

    public function test_it_refuses_a_duplicate_embg_within_the_same_company(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['embg' => '3101980455019']);
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->call('save')
            ->assertHasErrors(['embg']);
    }

    public function test_editing_shows_the_existing_salary_history(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);
        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-07-01', 'amount' => 35000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSee('30.000')
            ->assertSee('35.000');
    }

    public function test_editing_without_touching_salary_leaves_the_history_untouched(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $salary = EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->set('firstName', 'Ново Име')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('employee_salaries', 1);
        $this->assertDatabaseHas('employee_salaries', [
            'id' => $salary->id,
            'amount' => 30000,
            'basis' => 'net',
        ]);
    }

    public function test_it_refuses_to_open_an_employee_of_another_company(): void
    {
        // Both ids come from the URL and were authorised separately: 'view' on
        // the company, 'update' on the employee. An admin sees every company, so
        // without a guard tying the two together, saving would move the employee
        // — and their whole salary history — into the company from the URL.
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $employee = Employee::factory()->for($other)->create();

        $this->actAsAdmin();

        // Over HTTP, because that is the hole: the route binds the two models
        // independently, so the URL can pair any company with any employee.
        $this->get(route('employees.edit', [$company, $employee]))->assertNotFound();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'company_id' => $other->id]);
    }

    public function test_an_ordinary_edit_leaves_the_company_unchanged(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->set('firstName', 'Ново Име')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'Ново Име',
            'company_id' => $company->id,
        ]);
    }

    public function test_saving_the_same_effective_date_twice_updates_the_one_row(): void
    {
        // The `date` cast writes '2026-07-01 00:00:00' on SQLite, so a lookup key
        // of '2026-07-01' used to miss the row it had just written and append a
        // second one. salaryOn() then returned an arbitrary one of the two.
        $company = Company::factory()->create();
        $this->actAsAdmin();

        $component = Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('gross', '38507')
            ->call('save')
            ->assertHasNoErrors();

        $component->set('gross', '40000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('employee_salaries', 1);
        $this->assertDatabaseHas('employee_salaries', ['amount' => 40000, 'basis' => 'gross']);
    }

    public function test_it_refuses_to_save_without_a_municipality(): void
    {
        // sifra_opstina is a ★ column in the spec: МПИН needs it, so a card
        // saved without one would be unusable in phase 5c.
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->call('save')
            ->assertHasErrors(['municipalityCode' => 'required']);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_changing_the_effective_date_recomputes_the_other_side(): void
    {
        // A period of its own, because the three seeded 2026 periods all happen
        // to total 28% in contributions and share the tax rate and the personal
        // allowance — gross→net is identical across all three, so they cannot
        // show this. Here the pension rate alone moves the answer.
        $company = Company::factory()->create();

        PayrollParameter::create([
            'effective_from' => '2027-01-01', 'rate_pension' => 30, 'rate_health' => 7.5,
            'rate_injury' => 0.5, 'rate_unemployment' => 0.1, 'rate_tax' => 10,
            'personal_allowance' => 10932, 'average_salary' => 72000, 'min_base' => 36000,
            'max_base' => 1152000, 'minimum_wage' => 40000,
        ]);

        $this->actAsAdmin();

        $component = Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('gross', '38507')
            ->assertSet('net', '26046');

        // Before the fix the figure on screen stayed at the July rates' answer.
        $component->set('salaryEffectiveFrom', '2027-01-01')
            ->assertNotSet('net', '26046');

        $this->assertLessThan(26046, (float) $component->get('net'));
    }

    public function test_moving_the_date_outside_the_known_periods_raises_the_error(): void
    {
        // The recompute must not bypass the RuntimeException catch: typing the
        // amount first and moving the date afterwards has to reach the same
        // Macedonian message as doing it the other way round.
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('gross', '38507')
            ->assertHasNoErrors()
            ->set('salaryEffectiveFrom', '2025-06-30')
            ->assertHasErrors(['salaryEffectiveFrom'])
            ->assertSee('Нема параметри за пресметка на плата за овој датум');
    }

    public function test_the_card_shows_the_salary_in_force_on_the_working_years_date(): void
    {
        // The spec: the card покажува the salary in force — it shows it, it does
        // not offer it for editing. The assertions target the display line's own
        // markup because the history table below repeats the same numbers.
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2024-01-01', 'amount' => 28000, 'basis' => 'net',
        ]);
        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();
        WorkingYear::set($company, 2025);

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSee('Важечка плата')
            ->assertSeeHtml('<span class="font-medium text-gray-800">28.000</span>')
            ->assertDontSeeHtml('<span class="font-medium text-gray-800">30.000</span>')
            ->assertSee('Запис од 2024');
    }

    public function test_the_card_leaves_the_two_salary_inputs_blank(): void
    {
        // Prefilling them plus a "Важи од" defaulted to today would write a new
        // salary row on every save of an unrelated field.
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSet('gross', '')
            ->assertSet('net', '');
    }

    public function test_a_salary_agreed_in_the_working_year_carries_no_pill_on_the_card(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => now()->startOfYear()->toDateString(), 'amount' => 31000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSee('Важечка плата')
            ->assertDontSee('Запис од');
    }

    public function test_the_card_says_so_when_no_salary_has_been_agreed(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSee('Сè уште нема договорена плата')
            ->assertDontSee('Важечка плата');
    }

    public function test_it_stores_prior_service(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->set('prior_service_months', 24)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(24, Employee::where('embg', '3101980455019')->first()->prior_service_months);
    }

    public function test_a_client_may_edit_their_own_companys_employee(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->set('firstName', 'Изменето')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Изменето']);
    }
}
