<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Листата на изводи: по основно се гледаат само непрокнижените; организатор
 * со состојба, вид, сметка, период и подредување.
 */
class BankStatementIndexOrganizerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function statement(int $number, string $date, array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge(['number' => $number, 'statement_date' => $date], $attrs));
    }

    private function booked(int $number, string $date, array $attrs = []): BankStatement
    {
        return $this->statement($number, $date, array_merge(['status' => BankStatement::STATUS_BOOKED], $attrs));
    }

    private function screen()
    {
        return Livewire::test(BankStatementIndex::class, ['company' => $this->company]);
    }

    /** Броевите на изводите што се покажани, по редот на екранот. */
    private function shown($component): array
    {
        return collect($component->viewData('visible'))
            ->flatMap(fn (array $group) => $group['rows'])
            ->where('type', 'statement')
            ->map(fn (array $row) => $row['statement']->number)
            ->values()
            ->all();
    }

    public function test_booked_statements_are_hidden_by_default(): void
    {
        $this->statement(1, '2026-01-05');
        $this->booked(2, '2026-01-06');
        $this->statement(3, '2026-01-07');

        $component = $this->screen()->assertSet('statusFilter', 'open');

        $this->assertSame([1, 3], $this->shown($component));
    }

    public function test_the_booked_ones_can_be_shown_on_their_own_or_together_with_the_rest(): void
    {
        $this->statement(1, '2026-01-05');
        $this->booked(2, '2026-01-06');

        $this->assertSame([2], $this->shown($this->screen()->set('statusFilter', 'booked')));
        $this->assertSame([1, 2], $this->shown($this->screen()->set('statusFilter', 'all')));
    }

    public function test_hiding_booked_statements_does_not_invent_a_gap_in_the_sequence(): void
    {
        $this->statement(1, '2026-01-05');
        $this->booked(2, '2026-01-06');
        $this->statement(3, '2026-01-07');

        $html = $this->screen()->html();

        $this->assertStringNotContainsString('Недостига извод', $html);
        $this->assertStringNotContainsString('Недостигаат', $html);
    }

    public function test_a_real_gap_is_still_reported_when_the_view_is_filtered(): void
    {
        $this->statement(1, '2026-01-05');
        $this->booked(2, '2026-01-06');
        $this->statement(5, '2026-01-09'); // фалат 3 и 4

        $this->screen()->assertSee('Недостигаат изводите од 3 до 4');
    }

    public function test_the_kind_filter(): void
    {
        $this->statement(1, '2026-01-05');
        $this->statement(1, '2026-01-05', ['kind' => BankStatementKind::FOREIGN, 'account' => '300000000000001']);

        $this->assertSame(
            [BankStatementKind::FOREIGN],
            collect($this->screen()->set('kindFilter', BankStatementKind::FOREIGN->value)->viewData('visible'))->pluck('kind')->all()
        );
    }

    public function test_the_account_filter(): void
    {
        $this->statement(1, '2026-01-05', ['account' => 'AAA']);
        $this->statement(2, '2026-01-06', ['account' => 'BBB']);

        $this->assertSame([2], $this->shown($this->screen()->set('accountFilter', 'BBB')));
    }

    public function test_the_period_filter(): void
    {
        $this->statement(1, '2026-01-05');
        $this->statement(2, '2026-02-10');
        $this->statement(3, '2026-03-15');

        $component = $this->screen()->set('dateFrom', '2026-02-01')->set('dateTo', '2026-02-28');

        $this->assertSame([2], $this->shown($component));
    }

    public function test_sorting_by_date_in_either_direction(): void
    {
        $this->statement(1, '2026-01-20');
        $this->statement(2, '2026-01-05');
        $this->statement(3, '2026-01-10');

        $this->assertSame([2, 3, 1], $this->shown($this->screen()->set('sortBy', 'date')->set('sortDirection', 'asc')));
        $this->assertSame([1, 3, 2], $this->shown($this->screen()->set('sortBy', 'date')->set('sortDirection', 'desc')));
    }

    public function test_sorting_by_number_descending(): void
    {
        $this->statement(1, '2026-01-05');
        $this->statement(2, '2026-01-06');
        $this->statement(3, '2026-01-07');

        $this->assertSame([3, 2, 1], $this->shown($this->screen()->set('sortBy', 'number')->set('sortDirection', 'desc')));
    }

    public function test_unknown_organizer_values_fall_back_to_the_defaults(): void
    {
        $this->statement(1, '2026-01-05');
        $this->booked(2, '2026-01-06');

        $component = $this->screen()
            ->set('statusFilter', 'whatever')
            ->set('sortBy', 'whatever')
            ->set('sortDirection', 'sideways')
            ->set('dateFrom', 'not a date');

        $this->assertSame([1], $this->shown($component));
    }

    public function test_the_1000_check_does_not_depend_on_the_filter(): void
    {
        $this->booked(1, '2026-01-05');

        $this->screen()->assertSee('Контрола на конто 1000');
    }

    public function test_an_empty_result_says_so_and_points_to_the_filter(): void
    {
        $this->booked(1, '2026-01-05');

        $this->screen()->assertSee('Нема изводи за избраниот филтер');
    }
}
