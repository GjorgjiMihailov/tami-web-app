<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Движење меѓу изводите на иста сметка (по број, во иста година): претходен и
 * следен, со зачувување на тековниот нацрт пред да се замине.
 */
class BankStatementBookNavigationTest extends TestCase
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

    private function statement(int $number, array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge(['number' => $number, 'statement_date' => '2026-03-05'], $attrs));
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function bookUrl(BankStatement $statement): string
    {
        return route('bank-statements.book', [$this->company, $statement]);
    }

    public function test_next_and_previous_go_to_the_neighbouring_numbers(): void
    {
        $one = $this->statement(1);
        $two = $this->statement(2);
        $three = $this->statement(3);

        $this->screen($two)->call('goToSibling', 'next')->assertRedirect($this->bookUrl($three));
        $this->screen($two)->call('goToSibling', 'previous')->assertRedirect($this->bookUrl($one));
    }

    public function test_a_gap_in_the_numbers_is_skipped_to_the_nearest_existing_statement(): void
    {
        $two = $this->statement(2);
        $five = $this->statement(5);

        $this->screen($two)->call('goToSibling', 'next')->assertRedirect($this->bookUrl($five));
    }

    public function test_booked_statements_are_part_of_the_walk(): void
    {
        $one = $this->statement(1);
        $two = $this->statement(2, ['status' => BankStatement::STATUS_BOOKED]);

        $this->screen($one)->call('goToSibling', 'next')->assertRedirect($this->bookUrl($two));
    }

    public function test_only_the_same_account_and_year_count(): void
    {
        $this->statement(1, ['account' => 'AAA']);
        $current = $this->statement(2, ['account' => 'AAA']);
        $this->statement(3, ['account' => 'BBB']);              // друга сметка
        $this->statement(3, ['account' => 'AAA', 'statement_date' => '2027-01-05']); // друга година

        $component = $this->screen($current);

        $this->assertNull($component->viewData('siblings')['next']);
    }

    public function test_the_ends_have_no_button_target_and_do_nothing(): void
    {
        $only = $this->statement(1);

        $component = $this->screen($only);

        $this->assertSame(['previous' => null, 'next' => null], $component->viewData('siblings'));
        $component->call('goToSibling', 'next')->assertNoRedirect();
    }

    public function test_the_draft_is_saved_before_leaving(): void
    {
        $one = $this->statement(1);
        $two = $this->statement(2);

        $this->screen($one)
            ->set('openingBalance', '100.00')
            ->set('closingBalance', '100.00')
            ->call('goToSibling', 'next')
            ->assertRedirect($this->bookUrl($two));

        $this->assertSame('100.00', $one->fresh()->opening_balance);
    }

    public function test_an_invalid_draft_blocks_leaving_and_says_why(): void
    {
        $one = $this->statement(1);
        $this->statement(2);

        $this->screen($one)
            ->call('addLine')
            ->set('lines.0.amount', 'abc')
            ->call('goToSibling', 'next')
            ->assertNoRedirect()
            ->assertHasErrors(['lines.0.amount']);
    }

    public function test_a_booked_statement_is_left_without_saving_anything(): void
    {
        $one = $this->statement(1, ['status' => BankStatement::STATUS_BOOKED]);
        $two = $this->statement(2);

        $this->screen($one)->call('goToSibling', 'next')->assertRedirect($this->bookUrl($two));
    }

    public function test_the_buttons_show_the_neighbouring_numbers(): void
    {
        $this->statement(1);
        $two = $this->statement(2);
        $this->statement(3);

        $this->screen($two)->assertSee('Извод 1')->assertSee('Извод 3');
    }

    public function test_an_unknown_direction_does_nothing(): void
    {
        $one = $this->statement(1);
        $this->statement(2);

        $this->screen($one)->call('goToSibling', 'sideways')->assertNoRedirect();
    }
}
