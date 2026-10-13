<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankStatementBookSuggestionsTest extends TestCase
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

        BankCounterparty::create([
            'company_id' => $this->company->id, 'account_key' => '100000000000007',
            'account_id' => $this->konto()->id,
        ]);
    }

    private function konto(): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
    }

    /** Две ставки од позната сметка (10 и 4) и една од непозната (6). Од 0.00 до 0.00. */
    private function importedStatement(): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement([
                'number' => 1, 'date' => '2026-03-05', 'opening' => '0.00',
                'lines' => [
                    ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000007'],
                    ['dir' => 'out', 'amount' => '4.00', 'party' => '100000000000007'],
                    ['dir' => 'out', 'amount' => '6.00', 'party' => '100000000000009'],
                ],
            ]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    public function test_the_button_shows_how_many_suggestions_there_are(): void
    {
        $this->screen($this->importedStatement())
            ->assertSee('Потврди ги сите предложени (2)')
            ->assertSee('3 ставки чекаат проверка');
    }

    public function test_confirming_all_suggested_leaves_the_unclear_line_pending(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->call('confirmSuggested')
            ->assertSee('1 ставка чека проверка')
            ->assertDontSee('Потврди ги сите предложени');

        $lines = $statement->fresh()->lines()->get();
        $this->assertNotNull($lines[0]->reviewed_at);
        $this->assertNotNull($lines[1]->reviewed_at);
        $this->assertNull($lines[2]->reviewed_at);
    }

    public function test_after_confirming_the_suggested_and_handling_the_rest_the_statement_books(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->call('confirmSuggested')
            ->set('lines.2.kind', 'account')
            ->set('lines.2.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
    }

    public function test_a_check_line_without_an_account_is_not_confirmed(): void
    {
        $statement = $this->importedStatement();
        // Предложена ставка чие конто веќе не е избрано (на пример избришано).
        $statement->lines()->firstOrFail()->update(['account_id' => null]);

        $this->screen($statement->fresh())->call('confirmSuggested');

        $lines = $statement->fresh()->lines()->get();
        $this->assertNull($lines[0]->reviewed_at);
        $this->assertNotNull($lines[1]->reviewed_at);
    }

    public function test_a_booked_statement_is_not_touched(): void
    {
        $statement = $this->importedStatement();
        $statement->update(['status' => BankStatement::STATUS_BOOKED]);

        $this->screen($statement->fresh())->call('confirmSuggested');

        $this->assertSame(0, $statement->fresh()->lines()->whereNotNull('reviewed_at')->count());
    }
}
