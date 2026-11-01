<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Livewire\Bank\BankStatementIndex;
use App\Livewire\Bank\ForeignStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Bank\Settlement1039;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\BankStatementKind;
use App\Support\JournalGroupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Поправки по завршната ревизија на дел 2. */
class ForeignStatementReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => false]);
        $this->partner = Partner::factory()->for($this->company)->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $this->company->id)->where('code', $code))
            ->get()->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');
    }

    private function conversion(string $mkd, string $foreign): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'journal_group_id' => JournalGroupCodes::group($this->company, JournalGroupCodes::FOREIGN_BANK)->id,
            'entry_date' => '2026-10-20', 'description' => 'Конверзија', 'created_by' => $this->admin->id,
        ]);
        $entry->lines()->create([
            'account_id' => $this->account('1039')->id, 'debit' => $mkd, 'credit' => '0', 'line_date' => '2026-10-20',
            'currency_code' => 'EUR', 'foreign_amount' => $foreign, 'exchange_rate' => '61.5',
        ]);
        $entry->lines()->create(['account_id' => $this->account('1030')->id, 'debit' => '0', 'credit' => $mkd, 'line_date' => '2026-10-20']);
    }

    private function denar(int $number, string $amount, array $lineAttrs = []): BankStatement
    {
        $statement = BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::DENAR, 'statement_date' => '2026-10-25', 'number' => $number,
            'opening_balance' => '0.00', 'closing_balance' => $amount, 'account' => '300000000000009',
        ]);
        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-10-25', 'direction' => LineDirection::IN, 'kind' => LineKind::ACCOUNT,
            'account_id' => $this->account('1039')->id, 'amount' => $amount, 'foreign_amount' => '1000.00', 'currency' => 'EUR',
        ], $lineAttrs));

        return $statement->fresh();
    }

    private function foreignInvoice(string $amount = '1000.00'): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create([
            'partner_id' => $this->partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $amount, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);
    }

    private function foreignStatement(array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'statement_date' => '2026-10-20', 'number' => 1,
        ], $attrs));
    }

    public function test_a_booked_settlement_is_not_proposed_again_in_the_next_cycle(): void
    {
        $this->conversion('61500.00', '1000.00');
        $first = $this->denar(1, '61450.00', ['settle_difference' => true]);
        app(BankStatementPoster::class)->post($first, $this->admin->id);
        $this->assertSame('0.00', $this->balance('1039'));

        $this->conversion('61500.00', '1000.00');
        $second = $this->denar(2, '61500.00');

        $this->assertSame([], Settlement1039::proposals($second));
    }

    public function test_a_line_on_1039_without_a_foreign_amount_is_refused(): void
    {
        $statement = $this->denar(1, '1000.00', ['foreign_amount' => null, 'currency' => null]);

        $this->assertStringContainsString('девизен износ', implode(' ', StatementControls::problems($statement)));
    }

    public function test_changing_the_amount_clears_an_accepted_settlement(): void
    {
        $this->conversion('61500.00', '1000.00');
        $statement = $this->denar(1, '61450.00', ['settle_difference' => true]);

        Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->assertSet('lines.0.settle_difference', true)
            ->set('lines.0.credit', '61400.00')
            ->assertSet('lines.0.settle_difference', false);
    }

    public function test_changing_the_account_clears_the_foreign_fields(): void
    {
        $statement = $this->denar(1, '61450.00', ['settle_difference' => true]);

        Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->assertSet('lines.0.foreign_amount', '')
            ->assertSet('lines.0.settle_difference', false)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_payment_already_booked_with_the_invoice_button_can_be_linked_and_is_not_posted_twice(): void
    {
        $invoice = $this->foreignInvoice();
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '1000.00', '2026-10-20', 'bank', $this->admin->id, '61.200000');
        $before = $this->balance('1030');
        $statement = $this->foreignStatement(['opening_balance' => '0', 'closing_balance' => '1000.00']);

        Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->call('addLine')
            ->set('lines.0.partner_id', $this->partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->set('lines.0.amount', '1000.00')
            ->set('lines.0.exchange_rate', '61.2')
            ->set('lines.0.existing_payment_id', $payment->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
        $this->assertSame($before, $this->balance('1030'));
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_the_foreign_screen_offers_unlinked_bank_payments_of_the_invoice(): void
    {
        $invoice = $this->foreignInvoice();
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '400.00', '2026-10-20', 'bank', $this->admin->id, '61.200000');

        $component = Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $this->foreignStatement()])
            ->call('addLine')
            ->set('lines.0.partner_id', $this->partner->id)
            ->set('lines.0.invoice_id', $invoice->id);

        $this->assertSame([$payment->id], array_column($component->instance()->paymentOptions(0), 'id'));
    }

    public function test_a_second_currency_on_the_same_account_is_refused_at_upload(): void
    {
        Storage::fake('google');
        BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'account' => '300000000000001', 'number' => 1, 'statement_date' => '2026-02-01',
        ]);

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('bank', 'Комерцијална')->set('account', '300000000000001')->set('kind', 'foreign')->set('currency', 'USD')
            ->set('number', '2')->set('statementDate', '2026-02-14')
            ->set('newFile', UploadedFile::fake()->create('i.pdf', 10))
            ->call('saveStatement')
            ->assertHasErrors('currency');
    }

    public function test_a_foreign_statement_without_a_currency_has_a_problem_not_a_crash(): void
    {
        $statement = $this->foreignStatement(['currency' => null, 'opening_balance' => '1000', 'closing_balance' => '0']);
        BankStatementLine::factory()->for($statement)->create([
            'line_date' => '2026-10-20', 'direction' => LineDirection::OUT, 'amount' => '1000.00', 'exchange_rate' => '61.5', 'kind' => LineKind::CURRENCY_SALE,
        ]);

        $this->assertStringContainsString('валута', implode(' ', StatementControls::problems($statement->fresh())));
    }

    public function test_an_oversized_rate_or_a_non_numeric_invoice_is_a_validation_error(): void
    {
        $statement = $this->foreignStatement();

        Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->call('addLine')
            ->set('lines.0.amount', '10')
            ->set('lines.0.exchange_rate', '1234567.5')
            ->call('save')
            ->assertHasErrors('lines.0.exchange_rate');

        Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->call('addLine')
            ->set('lines.0.amount', '10')
            ->set('lines.0.exchange_rate', '61.5')
            ->set('lines.0.invoice_id', 'abc')
            ->call('save')
            ->assertHasErrors('lines.0.invoice_id');
    }
}
