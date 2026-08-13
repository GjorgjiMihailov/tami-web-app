<?php

namespace Tests\Feature;

use App\Livewire\EmployeeForm;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
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
