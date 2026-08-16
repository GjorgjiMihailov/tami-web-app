<?php

namespace Tests\Feature\Payroll;

use App\Livewire\Payroll\PayrollRunShow;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollRunShowTest extends TestCase
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

    private function openRun(): PayrollRun
    {
        $company = Company::factory()->create();

        // No account seeding here. CompanyObserver seeds the whole official
        // chart on Company::created, and 421, 240, 249, 234 and 235 are all in
        // it — creating them by hand collides with the unique index.

        // firstOrCreate, not create: the hour fund is a national row, not a
        // per-company one, so a test that opens runs for two companies in the
        // same month would otherwise collide on its (year, month) unique index.
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);

        $employee = Employee::factory()->for($company)->create([
            'first_name' => 'Ана', 'last_name' => 'Николовска',
            // Hired inside the run year on purpose: at 2026-07-31 that is zero
            // completed years, so no seniority line is appended and the figures
            // stay УЈП's published ones. Tests that need seniority set their own
            // hire date.
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        return app(PayrollRunService::class)->open($company, 2026, 7);
    }

    public function test_it_shows_every_employee_with_their_figures(): void
    {
        $run = $this->openRun();
        $this->admin();

        // Two decimals, Macedonian separators — the same shape the ledger
        // holds, so the screen ties out against the journal entry to the denar.
        // Not 26.046: whole-denar rounding is МПИН's write rule in 5c, not a
        // display rule here.
        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->assertSee('Николовска')
            ->assertSee('26.045,73');
    }

    public function test_adding_a_deduction_lowers_the_amount_for_payment(): void
    {
        $run = $this->openRun();
        $this->admin();
        $runEmployeeId = $run->employees->first()->id;

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $runEmployeeId)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Кредит')
            ->set('lineAmount', 5000)
            ->call('saveLine')
            ->assertHasNoErrors();

        $this->assertSame(
            21046,
            (int) round($run->fresh()->employees->first()->effective_net)
        );
    }

    public function test_it_refuses_a_deduction_when_the_fund_bears_the_whole_month(): void
    {
        $run = $this->openRun();
        $this->admin();
        $runEmployee = $run->employees->first();
        $runEmployee->lines()->update([
            'code' => '129', 'borne_by' => PayrollRunLine::BORNE_FZO,
        ]);
        app(PayrollRunService::class)->recalculate($run->fresh());

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run->fresh()])
            ->call('selectEmployee', $runEmployee->id)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Кредит')
            ->set('lineAmount', 1000)
            ->call('saveLine')
            ->assertHasErrors('lineAmount')
            // The sentence itself is the user's decision, so pin it. Without
            // this the two guards could be merged into one generic message and
            // every assertion here would still pass.
            ->assertSee('Нема од што да се задржи');
    }

    public function test_it_refuses_a_deduction_larger_than_the_remaining_net(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Преголем кредит')
            ->set('lineAmount', 40000)
            ->call('saveLine')
            ->assertHasErrors('lineAmount')
            ->assertSee('Задршката е поголема од останатото нето за исплата.');
    }

    public function test_it_refuses_fractional_hours(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_HOURS)
            ->set('lineCode', '005')
            ->set('lineHours', '7.5')
            ->set('linePercent', 135)
            ->call('saveLine')
            ->assertHasErrors('lineHours');
    }

    public function test_it_refuses_a_code_outside_the_offered_set(): void
    {
        // A crafted payload could otherwise store any code, price normally,
        // and only fail much later at УЈП.
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_HOURS)
            ->set('lineCode', '999')
            ->set('lineHours', 10)
            ->set('linePercent', 100)
            ->call('saveLine')
            ->assertHasErrors('lineCode');
    }

    public function test_the_automatic_line_cannot_be_deleted(): void
    {
        $run = $this->openRun();
        $run->employees->first()->employee->update(['employed_on' => '2006-07-01']);
        app(PayrollRunService::class)->recalculate($run->fresh());
        $this->admin();

        $automatic = $run->fresh()->employees->first()->lines->firstWhere('is_automatic', true);
        $this->assertNotNull($automatic);

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->call('deleteLine', $automatic->id)
            ->assertHasErrors('lineKind');

        $this->assertDatabaseHas('payroll_run_lines', ['id' => $automatic->id]);
    }

    public function test_confirming_locks_the_run(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('confirm');

        $this->assertSame(PayrollRun::CONFIRMED, $run->fresh()->status);
    }

    public function test_it_cannot_delete_a_line_belonging_to_another_run(): void
    {
        $run = $this->openRun();
        $foreign = $this->openRun();
        $this->admin();

        $foreignLine = $foreign->employees->first()->lines->first();

        try {
            Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
                ->call('selectEmployee', $run->employees->first()->id)
                ->call('deleteLine', $foreignLine->id);

            $this->fail('A line belonging to another run must not be deletable from this one.');
        } catch (ModelNotFoundException $e) {
            // Expected: the scoped lookup must not find it at all.
        }

        $this->assertDatabaseHas('payroll_run_lines', ['id' => $foreignLine->id]);
    }

    public function test_a_confirmed_run_refuses_a_line_deletion(): void
    {
        $run = $this->openRun();
        $user = $this->admin();
        app(PayrollRunService::class)->confirm($run, $user->id);

        // Read the line AFTER confirming. confirm() recalculates, and
        // recalculate() deletes and reinserts every line, so an id captured
        // beforehand is legitimately gone — asserting on it would fail for a
        // reason that has nothing to do with deleteLine().
        $run = $run->fresh(['employees.lines']);
        $line = $run->employees->first()->lines->first();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->call('deleteLine', $line->id)
            ->assertHasErrors('lineKind');

        $this->assertDatabaseHas('payroll_run_lines', ['id' => $line->id]);
    }

    public function test_a_confirmed_run_refuses_a_second_confirmation(): void
    {
        $run = $this->openRun();
        $user = $this->admin();
        app(PayrollRunService::class)->confirm($run, $user->id);

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run->fresh()])
            ->call('confirm')
            ->assertHasErrors('lineKind');
    }

    public function test_a_confirmed_run_refuses_edits(): void
    {
        $run = $this->openRun();
        $user = $this->admin();
        app(PayrollRunService::class)->confirm($run, $user->id);

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run->fresh()])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Доцна')
            ->set('lineAmount', 100)
            ->call('saveLine')
            ->assertHasErrors('lineKind');
    }
}
