<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Livewire\Bank\ForeignStatementBook;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignStatementBookTest extends TestCase
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

    private function statement(array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'statement_date' => '2026-10-20', 'number' => 1,
        ], $attrs));
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function invoice(string $currency = 'EUR', string $amount = '1000.00', ?Company $company = null, ?Partner $partner = null): SalesInvoice
    {
        $company ??= $this->company;
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => ($partner ?? $this->partner)->id, 'invoice_date' => '2026-10-01',
            'currency' => $currency, 'exchange_rate' => $currency === 'MKD' ? '1' : '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $amount, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);
    }

    public function test_a_foreign_statement_opens_and_a_denar_one_is_404(): void
    {
        $this->screen($this->statement())->assertOk()->assertSee('девизен извод');

        $denar = $this->statement(['kind' => BankStatementKind::DENAR, 'currency' => null, 'number' => 2]);
        Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $denar])->assertNotFound();
        Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $this->statement(['number' => 3])])->assertNotFound();
    }

    public function test_another_companys_statement_is_404(): void
    {
        $other = Company::factory()->create(['type' => 'legal']);
        $statement = BankStatement::factory()->for($other)->create(['kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'statement_date' => '2026-10-20']);

        Livewire::test(ForeignStatementBook::class, ['company' => $this->company, 'statement' => $statement])->assertNotFound();
    }

    public function test_a_receipt_and_a_conversion_can_be_saved_with_their_rates(): void
    {
        $invoice = $this->invoice();
        $statement = $this->statement();

        $this->screen($statement)
            ->set('openingBalance', '0')
            ->set('closingBalance', '0')
            ->call('addLine')
            ->set('lines.0.partner_id', $this->partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->set('lines.0.exchange_rate', '61.2')
            ->call('addLine')
            ->set('lines.1.kind', 'currency_sale')
            ->set('lines.1.amount', '1000')
            ->set('lines.1.exchange_rate', '61.5027')
            ->call('save')
            ->assertHasNoErrors();

        [$receipt, $sale] = $statement->fresh()->lines->all();

        $this->assertSame('in', $receipt->direction->value);
        $this->assertSame('1000.00', (string) $receipt->amount);
        $this->assertSame('61.200000', (string) $receipt->exchange_rate);
        $this->assertSame($invoice->id, $receipt->sales_invoice_id);
        $this->assertSame('out', $sale->direction->value);
        $this->assertSame('61.502700', (string) $sale->exchange_rate);
        $this->assertNull($sale->sales_invoice_id);
        $this->assertSame('EUR', $sale->currency);
    }

    public function test_invoice_options_list_only_open_foreign_invoices_in_the_statements_currency(): void
    {
        $eur = $this->invoice('EUR');
        $this->invoice('USD');
        $this->invoice('MKD');
        $paid = $this->invoice('EUR', '500.00');
        app(SalesInvoiceService::class)->recordPayment($paid->fresh(), '500.00', '2026-10-10', 'bank', $this->admin->id, '61.4');
        $otherPartner = Partner::factory()->for($this->company)->create();
        $this->invoice('EUR', '300.00', null, $otherPartner);

        $component = $this->screen($this->statement())->call('addLine')->set('lines.0.partner_id', $this->partner->id);
        $options = $component->instance()->invoiceOptions(0);

        $this->assertSame([$eur->id], array_column($options, 'id'));
    }

    public function test_choosing_an_invoice_fills_the_open_balance(): void
    {
        $invoice = $this->invoice('EUR', '750.00');

        $this->screen($this->statement())
            ->call('addLine')
            ->set('lines.0.partner_id', $this->partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->assertSet('lines.0.amount', '750.00');
    }

    public function test_post_creates_the_entry_and_the_screen_shows_it_booked(): void
    {
        $invoice = $this->invoice();
        $statement = $this->statement(['opening_balance' => '0', 'closing_balance' => '0']);

        $this->screen($statement)
            ->set('openingBalance', '0')
            ->set('closingBalance', '600.00')
            ->call('addLine')
            ->set('lines.0.partner_id', $this->partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->set('lines.0.amount', '600.00')
            ->set('lines.0.exchange_rate', '61.2')
            ->call('post')
            ->assertHasNoErrors()
            ->assertSee('Прокнижен');

        $this->assertTrue($statement->fresh()->isBooked());
        $this->assertSame('400.00', $invoice->fresh()->balanceDue());
    }

    public function test_post_with_problems_shows_them_and_books_nothing(): void
    {
        $statement = $this->statement();

        $this->screen($statement)
            ->set('openingBalance', '0')
            ->set('closingBalance', '500.00')
            ->call('addLine')
            ->set('lines.0.kind', 'currency_sale')
            ->set('lines.0.amount', '100.00')
            ->call('post')
            ->assertHasErrors('post');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_fetching_the_rate_fills_it_in(): void
    {
        ExchangeRate::create(['rate_date' => '2026-10-20', 'currency_code' => 'EUR', 'rate' => '61.4955']);

        $this->screen($this->statement())
            ->call('addLine')
            ->set('lines.0.line_date', '2026-10-20')
            ->call('fetchRate', 0)
            ->assertSet('lines.0.exchange_rate', '61.4955');
    }

    public function test_a_failed_rate_fetch_reports_it_and_leaves_the_field(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->screen($this->statement())
            ->call('addLine')
            ->set('lines.0.line_date', '2026-10-21')
            ->call('fetchRate', 0)
            ->assertHasErrors('lines.0.exchange_rate')
            ->assertSet('lines.0.exchange_rate', '');
    }

    public function test_the_difference_strip_follows_the_currency_balances(): void
    {
        $component = $this->screen($this->statement())
            ->set('openingBalance', '500')
            ->set('closingBalance', '100')
            ->call('addLine')
            ->set('lines.0.kind', 'currency_sale')
            ->set('lines.0.amount', '300');

        $this->assertSame('100.00', $component->instance()->difference());
        $this->assertSame('300.00', $component->instance()->totals()['out']);
    }

    public function test_the_list_links_a_foreign_statement_to_its_own_screen(): void
    {
        $statement = $this->statement();

        $this->get(route('bank-statements.index', $this->company))
            ->assertOk()
            ->assertSee(route('bank-statements.book-foreign', [$this->company, $statement]));
    }
}
