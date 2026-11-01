<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\BankStatementKind;
use App\Support\JournalGroupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementBook1039Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->company = Company::factory()->create(['type' => 'legal']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function statement(): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::DENAR, 'statement_date' => '2026-10-25', 'number' => 1,
        ]);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function conversion(): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'journal_group_id' => JournalGroupCodes::group($this->company, JournalGroupCodes::FOREIGN_BANK)->id,
            'entry_date' => '2026-10-20', 'description' => 'Конверзија', 'created_by' => $this->admin->id,
        ]);
        $entry->lines()->create([
            'account_id' => $this->account('1039')->id, 'debit' => '424368.63', 'credit' => '0', 'line_date' => '2026-10-20',
            'currency_code' => 'EUR', 'foreign_amount' => '6900.00', 'exchange_rate' => '61.5027',
        ]);
        $entry->lines()->create(['account_id' => $this->account('1030')->id, 'debit' => '0', 'credit' => '424368.63', 'line_date' => '2026-10-20']);
    }

    public function test_the_foreign_fields_show_only_for_account_1039(): void
    {
        $statement = $this->statement();

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->assertDontSee('Девизен износ')
            ->set('lines.0.account_id', $this->account('1039')->id)
            ->assertSee('Девизен износ');
    }

    public function test_saving_stores_the_foreign_amount_currency_and_flag_only_for_1039(): void
    {
        $statement = $this->statement();

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.account_id', $this->account('1039')->id)
            ->set('lines.0.credit', '424338.96')
            ->set('lines.0.foreign_amount', '6900')
            ->set('lines.0.currency', 'EUR')
            ->set('lines.0.settle_difference', true)
            ->call('addLine')
            ->set('lines.1.account_id', $this->account('7400')->id)
            ->set('lines.1.credit', '100')
            ->set('lines.1.foreign_amount', '5')
            ->set('lines.1.currency', 'USD')
            ->set('lines.1.settle_difference', true)
            ->call('save')
            ->assertHasNoErrors();

        [$first, $second] = $statement->fresh()->lines->all();

        $this->assertSame('6900.00', (string) $first->foreign_amount);
        $this->assertSame('EUR', $first->currency);
        $this->assertTrue($first->settle_difference);
        $this->assertNull($second->foreign_amount);
        $this->assertNull($second->currency);
        $this->assertFalse($second->settle_difference);
    }

    public function test_the_proposal_shows_after_saving_and_can_be_accepted(): void
    {
        $this->conversion();
        $statement = $this->statement();

        $component = $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.account_id', $this->account('1039')->id)
            ->set('lines.0.credit', '424338.96')
            ->set('lines.0.foreign_amount', '6900')
            ->set('lines.0.currency', 'EUR')
            ->assertDontSee('Предлог: курсна разлика')
            ->call('save')
            ->assertSee('Предлог: курсна разлика')
            ->assertSee('29,67');

        $component->set('lines.0.settle_difference', true)->call('save');

        $this->assertTrue($statement->fresh()->lines->first()->settle_difference);
    }

    public function test_a_line_with_a_foreign_amount_but_a_bad_currency_is_refused_on_save(): void
    {
        $statement = $this->statement();

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.account_id', $this->account('1039')->id)
            ->set('lines.0.credit', '100')
            ->set('lines.0.foreign_amount', '5')
            ->set('lines.0.currency', 'GBP')
            ->call('save')
            ->assertHasErrors('lines.0.currency');
    }
}
