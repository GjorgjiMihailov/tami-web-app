<?php

namespace Tests\Feature\Payroll;

use App\Livewire\Payroll\PayrollRunIndex;
use App\Models\Company;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollRunIndexTest extends TestCase
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

    private function parameter(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-01-31');
    }

    public function test_it_lists_the_runs_of_the_working_year(): void
    {
        $company = Company::factory()->create();
        $parameter = $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2025, 'month' => 3,
            'status' => PayrollRun::DRAFT, 'month_hours' => 168,
            'payroll_parameter_id' => $parameter->id,
        ]);

        // assertDontSee would be fooled by the month picker, which lists all
        // twelve month names as options whatever the table holds. What the
        // working-year filter actually decides is the view data, so assert on
        // that, and keep one rendered check that the row reaches the page.
        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->assertViewHas('runs', fn ($runs) => $runs->pluck('month')->all() === [7])
            ->assertSee('Јули');
    }

    public function test_it_does_not_list_another_companys_runs(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $parameter = $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        PayrollRun::create([
            'company_id' => $other->id, 'year' => 2026, 'month' => 5,
            'status' => PayrollRun::DRAFT, 'month_hours' => 168,
            'payroll_parameter_id' => $parameter->id,
        ]);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->assertViewHas('runs', fn ($runs) => $runs->isEmpty());
    }

    public function test_it_opens_a_new_month(): void
    {
        $company = Company::factory()->create();
        $this->parameter();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->admin();
        WorkingYear::set($company, 2026);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 7)
            ->call('createRun')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payroll_runs', [
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
        ]);
    }

    public function test_it_refuses_a_month_that_is_already_open(): void
    {
        $company = Company::factory()->create();
        $this->parameter();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->admin();
        WorkingYear::set($company, 2026);

        app(PayrollRunService::class)->open($company, 2026, 7);

        // Asserting the message, not just that an error exists: the raw
        // SQLSTATE text registers as an error too, so a bare assertHasErrors
        // would pass on the very bug this test is here to catch.
        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 7)
            ->call('createRun')
            ->assertHasErrors('newMonth')
            ->assertSee('За тој месец веќе постои пресметка.');
    }

    public function test_it_reports_a_missing_hour_fund_as_a_form_error(): void
    {
        $company = Company::factory()->create();
        $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 9)
            ->call('createRun')
            ->assertHasErrors('newMonth');
    }
}
