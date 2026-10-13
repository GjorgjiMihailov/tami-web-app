<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankStatementBookReviewFlagsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->company = Company::factory()->create();
    }

    /** Увезен извод: уплата 10.00 и исплата 4.00, од 0.00 до 6.00. */
    private function importedStatement(): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement([
                'number' => 1, 'date' => '2026-03-05',
                'lines' => [
                    ['dir' => 'in', 'amount' => '10.00', 'name' => 'ONE'],
                    ['dir' => 'out', 'amount' => '4.00', 'name' => 'TWO'],
                ],
            ]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function konto(): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
    }

    public function test_imported_lines_show_their_mark_and_the_pending_count(): void
    {
        $this->screen($this->importedStatement())
            ->assertSee('Нејасно')
            ->assertSee('2 ставки чекаат проверка');
    }

    public function test_choosing_an_account_in_the_table_marks_the_line_reviewed(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('save')
            ->assertSee('1 ставка чека проверка');

        $lines = $statement->fresh()->lines()->get();
        $this->assertNotNull($lines[0]->reviewed_at);
        $this->assertNull($lines[1]->reviewed_at);
    }

    public function test_the_reviewed_mark_and_the_import_marks_survive_saving_twice(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('save')
            ->call('save');

        $first = $statement->fresh()->lines()->firstOrFail();
        $this->assertNotNull($first->reviewed_at);
        $this->assertSame('unclear', $first->match_confidence->value);
        $this->assertSame('Другата страна не е препознаена.', $first->match_reason);
    }

    public function test_posting_is_blocked_while_a_line_is_unreviewed(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasErrors('post');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_posting_works_once_every_line_is_handled(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->set('lines.1.kind', 'account')
            ->set('lines.1.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
    }

    public function test_a_hand_entered_statement_has_no_pending_lines(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->screen($statement)
            ->call('addLine')
            ->assertDontSee('чекаат проверка')
            ->assertDontSee('чека проверка');
    }
}
