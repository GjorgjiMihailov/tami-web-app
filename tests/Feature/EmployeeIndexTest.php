<?php

namespace Tests\Feature;

use App\Livewire\EmployeeIndex;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
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
        $this->actingAs($admin);

        return $admin;
    }

    public function test_it_lists_the_companys_employees(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['first_name' => 'Ана', 'last_name' => 'Николовска']);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('Ана')
            ->assertSee('Николовска');
    }

    public function test_it_does_not_list_another_companys_employees(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        Employee::factory()->for($other)->create(['first_name' => 'Туѓа', 'last_name' => 'Фирма']);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertDontSee('Туѓа');
    }

    public function test_terminated_employees_are_hidden_until_asked_for(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create([
            'embg' => '3101980455019', 'first_name' => 'Стефан', 'last_name' => 'Стар',
            'employed_on' => '2020-01-01', 'terminated_on' => '2024-06-30',
        ]);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertDontSee('Стефан')
            ->set('showTerminated', true)
            ->assertSee('Стефан');
    }

    public function test_an_employee_hired_after_a_past_working_year_is_still_listed(): void
    {
        // "Вработените се матични податоци… листата не се филтрира по работна
        // година." Deciding active/terminated on the working year's date would
        // hide everyone hired later behind a checkbox that promises the opposite
        // — terminated staff, not future hires.
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create([
            'first_name' => 'Нова', 'last_name' => 'Вработена',
            'employed_on' => now()->startOfYear()->toDateString(),
        ]);

        $this->admin();
        WorkingYear::set($company, (int) now()->year - 1);

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('Вработена');
    }

    public function test_it_shows_the_salary_in_force_in_the_working_year(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('30.000');
    }

    public function test_a_salary_from_an_earlier_year_is_marked_as_such(): void
    {
        // The spec's rule: when the figure on screen is not today's, say so,
        // using the same grey pill already used for records outside the year.
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2024-05-01', 'amount' => 28000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('Запис од 2024');
    }

    public function test_a_salary_agreed_in_the_working_year_carries_no_pill(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => now()->startOfYear()->toDateString(), 'amount' => 31000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('31.000')
            ->assertDontSee('Запис од');
    }

    public function test_the_table_carries_the_shared_data_table_treatment(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create();
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false)
            ->assertSee('py-1 px-3', false);
    }

    public function test_the_page_renders_over_http(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->get(route('employees.index', $company))->assertOk();
    }
}
