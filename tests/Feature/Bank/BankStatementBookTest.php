<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementBookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function draft(array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge(['statement_date' => '2026-03-05'], $attrs));
    }

    private function confirmedInvoice(Partner $partner, string $price = '300.00'): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        return $invoice;
    }

    public function test_the_screen_loads_for_an_admin(): void
    {
        $statement = $this->draft();

        $this->get(route('bank-statements.book', [$this->company, $statement]))->assertOk()->assertSee('Книжи извод');
    }

    public function test_a_client_of_the_company_cannot_open_it(): void
    {
        $client = User::factory()->create(['company_id' => $this->company->id]);
        $client->assignRole('internal_client');
        $statement = $this->draft();

        $this->actingAs($client)->get(route('bank-statements.book', [$this->company, $statement]))->assertForbidden();
    }

    public function test_a_statement_of_another_company_is_not_found(): void
    {
        $statement = BankStatement::factory()->create();

        $this->get(route('bank-statements.book', [$this->company, $statement]))->assertNotFound();
    }

    public function test_a_foreign_statement_is_not_found(): void
    {
        $statement = BankStatement::factory()->foreign()->for($this->company)->create();

        $this->get(route('bank-statements.book', [$this->company, $statement]))->assertNotFound();
    }

    public function test_lines_and_balances_are_saved_and_the_difference_updates_live(): void
    {
        $statement = $this->draft();

        $component = $this->screen($statement)
            ->set('openingBalance', '1000.00')
            ->set('closingBalance', '1500.00')
            ->call('addLine')
            ->set('lines.0.amount', '450.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id);

        $this->assertSame('-50.00', $component->instance()->difference);

        $component->set('lines.0.amount', '500.00');
        $this->assertSame('0.00', $component->instance()->difference);

        $component->call('save')->assertHasNoErrors();

        $fresh = $statement->fresh(['lines']);
        $this->assertSame('1000.00', $fresh->opening_balance);
        $this->assertCount(1, $fresh->lines);
        $this->assertSame('500.00', (string) $fresh->lines->first()->amount);
        $this->assertSame('2026-03-05', $fresh->lines->first()->line_date->toDateString());
    }

    public function test_an_invalid_amount_is_rejected(): void
    {
        $statement = $this->draft();

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.amount', 'abc')
            ->call('save')
            ->assertHasErrors('lines.0.amount');

        $this->assertSame(0, $statement->lines()->count());
    }

    public function test_save_replaces_the_lines_and_removing_one_deletes_it(): void
    {
        $statement = $this->draft();
        BankStatementLine::factory()->for($statement)->count(2)->create();

        $this->screen($statement->fresh())
            ->call('removeLine', 0)
            ->call('save');

        $this->assertSame(1, $statement->lines()->count());
    }

    public function test_post_books_the_statement(): void
    {
        $statement = $this->draft();

        $this->screen($statement)
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '500.00')
            ->call('addLine')
            ->set('lines.0.amount', '500.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
    }

    public function test_post_shows_the_problems_instead_of_booking(): void
    {
        $statement = $this->draft();

        $this->screen($statement)
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '999.00')
            ->call('addLine')
            ->set('lines.0.amount', '500.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post')
            ->assertHasErrors('post');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_choosing_a_partner_offers_their_open_invoices_and_posting_pays_the_invoice(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedInvoice($partner);
        $statement = $this->draft();

        $component = $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.direction', 'in')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id);

        $options = $component->instance()->invoiceOptions(0);
        $this->assertCount(1, $options);
        $this->assertSame($invoice->id, $options[0]['id']);

        $component->set('lines.0.invoice_id', $invoice->id);
        $this->assertSame('300.00', $component->get('lines.0.amount'));

        $component->set('openingBalance', '0.00')
            ->set('closingBalance', '300.00')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame('paid', $invoice->fresh(['lines', 'payments'])->paymentStatus());
    }

    public function test_a_fully_paid_invoice_is_not_offered(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedInvoice($partner);
        app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '300.00', '2026-03-04', 'bank', $this->admin->id);

        $component = $this->screen($this->draft())
            ->call('addLine')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id);

        $this->assertSame([], $component->instance()->invoiceOptions(0));
    }

    public function test_an_existing_bank_payment_can_be_linked_instead_of_booking_again(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedInvoice($partner);
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '300.00', '2026-03-04', 'bank', $this->admin->id);
        $statement = $this->draft();

        $component = $this->screen($statement)
            ->call('addLine')
            ->set('lines.0.direction', 'in')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->set('lines.0.amount', '300.00');

        $options = $component->instance()->paymentOptions(0);
        $this->assertCount(1, $options);
        $this->assertSame($payment->id, $options[0]['id']);

        $component->set('lines.0.existing_payment_id', $payment->id)
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '300.00')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
        $this->assertSame(1, SalesInvoicePayment::where('sales_invoice_id', $invoice->id)->count());
        $this->assertCount(0, $statement->fresh()->journalEntry->lines);
    }

    public function test_a_payment_already_linked_elsewhere_is_not_offered(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedInvoice($partner);
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '300.00', '2026-03-04', 'bank', $this->admin->id);
        $other = $this->draft(['number' => 7]);
        BankStatementLine::factory()->for($other)->create([
            'kind' => 'invoice_payment', 'sales_invoice_id' => $invoice->id, 'sales_invoice_payment_id' => $payment->id,
        ]);

        $component = $this->screen($this->draft(['number' => 8]))
            ->call('addLine')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id)
            ->set('lines.0.invoice_id', $invoice->id);

        $this->assertSame([], $component->instance()->paymentOptions(0));
    }

    public function test_reopen_returns_the_statement_to_a_draft(): void
    {
        $statement = $this->draft();
        $this->screen($statement)
            ->set('openingBalance', '0.00')->set('closingBalance', '500.00')
            ->call('addLine')->set('lines.0.amount', '500.00')->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post');

        $this->screen($statement->fresh())->call('reopen');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_a_booked_statement_cannot_be_edited(): void
    {
        $statement = $this->draft(['status' => BankStatement::STATUS_BOOKED]);

        $this->screen($statement)->call('addLine')->assertSet('lines', []);
    }
}
