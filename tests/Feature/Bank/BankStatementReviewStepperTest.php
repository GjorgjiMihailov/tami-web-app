<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankStatementReviewStepperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->company = Company::factory()->create();
    }

    /** @param  list<array<string, string>>  $lines */
    private function importedStatement(array $lines): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement(['number' => 1, 'date' => '2026-03-05', 'lines' => $lines]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    private function twoLines(): BankStatement
    {
        return $this->importedStatement([
            ['dir' => 'in', 'amount' => '10.00', 'name' => 'ONE', 'refs' => '1111//2222', 'desc' => 'prv opis'],
            ['dir' => 'out', 'amount' => '4.00', 'name' => 'TWO'],
        ]);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function konto(): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
    }

    public function test_starting_opens_the_first_pending_line_and_shows_the_original_text(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->assertSet('reviewing', true)
            ->assertSet('reviewIndex', 0)
            ->assertSee('1 од 2')
            ->assertSee('ONE')
            ->assertSee('1111//2222')
            ->assertSee('prv opis');
    }

    public function test_nothing_to_review_does_not_open_the_mode(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->screen($statement)->call('startReview')->assertSet('reviewing', false);
    }

    public function test_confirming_without_a_choice_shows_an_error_and_stays(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->call('confirmAndNext')
            ->assertHasErrors('review')
            ->assertSet('reviewIndex', 0);

        $this->assertNull($statement->fresh()->lines()->firstOrFail()->reviewed_at);
    }

    public function test_confirming_an_account_line_saves_it_and_moves_on(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->assertHasNoErrors()
            ->assertSet('reviewIndex', 1);

        $first = $statement->fresh()->lines()->firstOrFail();
        $this->assertNotNull($first->reviewed_at);
        $this->assertSame('account', $first->kind->value);
        $this->assertSame($this->konto()->id, $first->account_id);
    }

    public function test_editing_inside_the_mode_does_not_confirm_the_line(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->assertSet('lines.0.reviewed', false)
            ->call('save')
            ->assertSet('lines.0.reviewed', false)
            ->call('skipLine')
            ->assertSet('reviewIndex', 1);

        $first = $statement->fresh()->lines()->firstOrFail();
        $this->assertNull($first->reviewed_at);
        $this->assertSame($this->konto()->id, $first->account_id);
    }

    public function test_the_last_confirmation_closes_the_mode_and_clears_the_queue(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->set('lines.1.kind', 'account')
            ->set('lines.1.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->assertSet('reviewing', false)
            ->assertDontSee('чекаат проверка');

        $this->assertSame(0, $statement->fresh()->lines()->whereNull('reviewed_at')->count());
    }

    public function test_after_a_break_the_confirmed_lines_stay_confirmed(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext');

        $this->screen($statement->fresh())
            ->assertSee('1 ставка чека проверка')
            ->call('startReview')
            ->assertSet('reviewIndex', 1);
    }

    public function test_back_returns_to_the_previous_line(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->call('skipLine')
            ->assertSet('reviewIndex', 1)
            ->call('previousLine')
            ->assertSet('reviewIndex', 0);
    }

    public function test_an_invoice_line_is_confirmed_with_partner_and_invoice(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        $statement = $this->importedStatement([['dir' => 'in', 'amount' => '300.00']]);

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->call('confirmAndNext')
            ->assertHasNoErrors()
            ->assertSet('reviewing', false);

        $line = $statement->fresh()->lines()->firstOrFail();
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertNotNull($line->reviewed_at);
    }

    public function test_a_forged_review_index_does_nothing(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('reviewIndex', 99)
            ->call('confirmAndNext')
            ->assertSet('reviewing', false);

        $this->assertSame(0, $statement->fresh()->lines()->whereNotNull('reviewed_at')->count());
    }

    public function test_a_booked_statement_cannot_be_reviewed(): void
    {
        $statement = $this->twoLines();
        $statement->update(['status' => BankStatement::STATUS_BOOKED]);

        $this->screen($statement->fresh())->call('startReview')->assertSet('reviewing', false);
    }

    public function test_a_failed_save_does_not_leave_the_line_marked(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->set('lines.0.amount', 'abc')
            ->call('confirmAndNext')
            ->assertHasErrors('lines.0.amount')
            ->assertSet('lines.0.reviewed', false);
    }

    public function test_a_failed_confirm_does_not_unconfirm_a_line_that_was_already_confirmed(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->call('previousLine')
            ->assertSet('reviewIndex', 0)
            ->set('lines.0.amount', 'abc')
            ->call('confirmAndNext')
            ->assertHasErrors('lines.0.amount')
            ->assertSet('lines.0.reviewed', true);
    }

    public function test_removing_a_line_closes_the_review_mode(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->call('skipLine')
            ->assertSet('reviewIndex', 1)
            ->call('removeLine', 1)
            ->assertSet('reviewing', false)
            ->assertSet('reviewIndex', null);
    }

    public function test_a_stale_window_cannot_save_or_book_over_a_newer_save(): void
    {
        $statement = $this->twoLines();

        $stale = $this->screen($statement);
        $this->screen($statement)->call('save');

        $stale->call('save')->assertHasErrors('post');
        $stale->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasErrors('post');

        $fresh = $statement->fresh();
        $this->assertFalse($fresh->isBooked());
        $this->assertSame('unclear', $fresh->lines()->firstOrFail()->match_confidence->value);
    }
}
