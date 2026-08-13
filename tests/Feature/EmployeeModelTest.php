<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_salary_in_force_on_a_date(): void
    {
        $employee = Employee::factory()->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);
        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-07-01', 'amount' => 35000, 'basis' => 'net',
        ]);

        $this->assertSame(30000.0, $employee->salaryOn('2026-06-30')?->amount);
        $this->assertSame(35000.0, $employee->salaryOn('2026-07-01')?->amount);
        $this->assertSame(35000.0, $employee->salaryOn('2026-12-31')?->amount);
    }

    public function test_it_returns_nothing_before_the_first_salary_record(): void
    {
        $employee = Employee::factory()->create();

        EmployeeSalary::factory()->for($employee)->create(['effective_from' => '2026-01-01']);

        $this->assertNull($employee->salaryOn('2025-12-31'));
    }

    public function test_salary_is_found_on_its_own_effective_from_date(): void
    {
        // Critical boundary test: a salary must be found on its own effective_from date,
        // not only on later dates. This catches date/datetime comparison bugs where
        // the column carries a time component in storage.
        $employee = Employee::factory()->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-07-01', 'amount' => 45000, 'basis' => 'net',
        ]);

        $this->assertSame(45000.0, $employee->salaryOn('2026-07-01')?->amount);
    }

    public function test_the_same_embg_may_exist_at_two_different_companies(): void
    {
        // One person can be employed by two of the firm's clients. Those are
        // two separate cards, so the uniqueness is per company, not global.
        $first = Company::factory()->create();
        $second = Company::factory()->create();

        Employee::factory()->for($first)->create(['embg' => '3101980455019']);
        Employee::factory()->for($second)->create(['embg' => '3101980455019']);

        $this->assertSame(2, Employee::where('embg', '3101980455019')->count());
    }

    public function test_the_same_embg_may_not_be_entered_twice_at_one_company(): void
    {
        $company = Company::factory()->create();

        Employee::factory()->for($company)->create(['embg' => '3101980455019']);

        $this->expectException(QueryException::class);

        Employee::factory()->for($company)->create(['embg' => '3101980455019']);
    }

    public function test_an_employee_is_active_until_their_termination_date(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2026-02-01',
            'terminated_on' => '2026-08-31',
        ]);

        $this->assertFalse($employee->isActiveOn('2026-01-31'));
        $this->assertTrue($employee->isActiveOn('2026-02-01'));
        $this->assertTrue($employee->isActiveOn('2026-08-31'));
        $this->assertFalse($employee->isActiveOn('2026-09-01'));
    }

    public function test_an_employee_without_a_termination_date_stays_active(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2026-02-01',
            'terminated_on' => null,
        ]);

        $this->assertTrue($employee->isActiveOn('2030-01-01'));
    }
}
