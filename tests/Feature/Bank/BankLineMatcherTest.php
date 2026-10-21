<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\BankLineRule;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Bank\Mt940Importer;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\BankLinePattern;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankLineMatcherTest extends TestCase
{
    use RefreshDatabase;

    private const PARTY = '100000000000002';

    private Company $company;

    private User $admin;

    private int $number = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->company = Company::factory()->create();
    }

    private function sales(Partner $partner, string $amount, string $date = '2026-03-01', ?Company $company = null): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($company ?? $this->company)->create(['partner_id' => $partner->id, 'invoice_date' => $date]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $amount, 'vat_rate' => '0']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id)->fresh();
    }

    private function purchase(Partner $partner, string $amount, string $supplierNumber): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'supplier_invoice_number' => $supplierNumber,
        ]);
        $invoice->lines()->create([
            'account_id' => Account::where('company_id', $this->company->id)->where('code', '4620')->firstOrFail()->id,
            'description' => 'Line', 'quantity' => '1', 'unit_price' => $amount, 'vat_rate' => '0',
        ]);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id)->fresh();
    }

    private function partner(string $name = 'Купувач Тест'): Partner
    {
        return Partner::factory()->for($this->company)->create(['name' => $name]);
    }

    /**
     * Увоз на извод со ставки; ги враќа создадените ставки по ред.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, BankStatementLine>
     */
    private function import(array $lines)
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt', 'text' => Mt940Fixture::statement(['number' => $this->number++, 'lines' => $lines]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId)->lines()->orderBy('position')->get();
    }

    /** @return array<string, mixed> */
    private function in(string $amount, string $desc, array $extra = []): array
    {
        return array_merge(['dir' => 'in', 'amount' => $amount, 'party' => self::PARTY, 'desc' => $desc], $extra);
    }

    // --- 1. број во текстот ---

    public function test_a_number_in_the_text_with_a_fitting_amount_is_sure(): void
    {
        $partner = $this->partner();
        $invoice = $this->sales($partner, '1000.00');

        $line = $this->import([$this->in('1000.00', 'fra '.$invoice->formattedNumber())])->first();

        $this->assertSame(LineKind::INVOICE_PAYMENT, $line->kind);
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertSame($partner->id, $line->partner_id);
        $this->assertSame(MatchConfidence::SURE, $line->match_confidence);
        $this->assertStringContainsString($invoice->formattedNumber(), (string) $line->match_reason);
        $this->assertNull($line->account_id);
    }

    public function test_a_partial_payment_is_sure_and_says_so(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');

        $line = $this->import([$this->in('400.00', 'faktura '.$invoice->formattedNumber())])->first();

        $this->assertSame(MatchConfidence::SURE, $line->match_confidence);
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertStringContainsString('Делумно', (string) $line->match_reason);
    }

    public function test_an_amount_above_the_balance_is_check_but_the_invoice_is_still_chosen(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');

        $line = $this->import([$this->in('1500.00', 'fra '.$invoice->formattedNumber())])->first();

        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertStringContainsString('надминува', (string) $line->match_reason);
    }

    public function test_two_numbers_in_the_text_are_check_with_no_choice(): void
    {
        $partner = $this->partner();
        $a = $this->sales($partner, '300.00');
        $b = $this->sales($partner, '700.00');

        $line = $this->import([$this->in('50.00', 'fra '.$a->formattedNumber().' i '.$b->formattedNumber())])->first();

        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertNull($line->sales_invoice_id);
        $this->assertStringContainsString($a->formattedNumber(), (string) $line->match_reason);
        $this->assertStringContainsString($b->formattedNumber(), (string) $line->match_reason);
        $this->assertSame($partner->id, $line->partner_id);
    }

    public function test_two_numbers_choose_the_one_whose_balance_equals_the_amount(): void
    {
        $partner = $this->partner();
        $a = $this->sales($partner, '300.00');
        $b = $this->sales($partner, '700.00');

        $line = $this->import([$this->in('700.00', 'fra '.$a->formattedNumber().' i '.$b->formattedNumber())])->first();

        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertSame($b->id, $line->sales_invoice_id);
    }

    public function test_an_outgoing_payment_matches_the_suppliers_number(): void
    {
        $partner = $this->partner('Добавувач Тест');
        $invoice = $this->purchase($partner, '2000.00', '08528/2026-NP');

        $line = $this->import([$this->in('2000.00', 'faktura br. 08528/2026-NP', ['dir' => 'out'])])->first();

        $this->assertSame(LineKind::INVOICE_PAYMENT, $line->kind);
        $this->assertSame($invoice->id, $line->purchase_invoice_id);
        $this->assertNull($line->sales_invoice_id);
        $this->assertSame(MatchConfidence::SURE, $line->match_confidence);
    }

    public function test_the_number_has_precedence_over_a_remembered_account(): void
    {
        $partner = $this->partner();
        $invoice = $this->sales($partner, '1000.00');
        $konto = Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
        BankCounterparty::create([
            'company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in',
            'account_id' => $konto->id, 'partner_id' => $partner->id,
        ]);

        $line = $this->import([$this->in('1000.00', $invoice->formattedNumber())])->first();

        $this->assertSame(LineKind::INVOICE_PAYMENT, $line->kind);
        $this->assertNull($line->account_id);
    }

    // --- 2. запомнет партнер + износ ---

    public function test_a_remembered_partner_with_one_invoice_of_exactly_the_amount_is_sure(): void
    {
        $partner = $this->partner();
        $this->sales($partner, '500.00');
        $wanted = $this->sales($partner, '1200.00');
        BankCounterparty::create(['company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in', 'partner_id' => $partner->id]);

        $line = $this->import([$this->in('1200.00', 'uplata')])->first();

        $this->assertSame($wanted->id, $line->sales_invoice_id);
        $this->assertSame(MatchConfidence::SURE, $line->match_confidence);
    }

    public function test_a_remembered_partner_with_two_invoices_of_the_amount_is_check_with_no_choice(): void
    {
        $partner = $this->partner();
        $this->sales($partner, '500.00');
        $this->sales($partner, '500.00');
        BankCounterparty::create(['company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in', 'partner_id' => $partner->id]);

        $line = $this->import([$this->in('500.00', 'uplata')])->first();

        $this->assertNull($line->sales_invoice_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertSame($partner->id, $line->partner_id);
    }

    public function test_a_remembered_account_is_kept_but_the_matching_invoice_is_mentioned(): void
    {
        $partner = $this->partner();
        $invoice = $this->sales($partner, '500.00');
        $konto = Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
        BankCounterparty::create(['company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in', 'partner_id' => $partner->id]);
        BankLineRule::create([
            'company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in',
            'pattern' => BankLinePattern::from('Vrakane na pozajmica'), 'account_id' => $konto->id,
        ]);

        $line = $this->import([$this->in('500.00', 'Vrakane na pozajmica')])->first();

        $this->assertSame(LineKind::ACCOUNT, $line->kind);
        $this->assertSame($konto->id, $line->account_id);
        $this->assertNull($line->sales_invoice_id);
        $this->assertStringContainsString($invoice->formattedNumber(), (string) $line->match_reason);
    }

    public function test_an_amount_that_is_the_sum_of_the_oldest_invoices_gets_a_hint(): void
    {
        $partner = $this->partner();
        $this->sales($partner, '100.00', '2026-01-10');
        $this->sales($partner, '200.00', '2026-02-10');
        BankCounterparty::create(['company_id' => $this->company->id, 'account_key' => self::PARTY, 'direction' => 'in', 'partner_id' => $partner->id]);

        $line = $this->import([$this->in('300.00', 'uplata')])->first();

        $this->assertNull($line->sales_invoice_id);
        $this->assertStringContainsString('збир', (string) $line->match_reason);
        $this->assertSame($partner->id, $line->partner_id);
    }

    // --- 3. име, 4. износ ---

    public function test_a_similar_name_with_an_invoice_of_the_amount_is_check(): void
    {
        $partner = $this->partner('Ѓорѓи Михаилов');
        $invoice = $this->sales($partner, '777.00');

        $line = $this->import([$this->in('777.00', 'uplata', ['name' => 'GORGI MIHAILOV', 'party' => '100000000000099'])])->first();

        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertStringContainsString('Името', (string) $line->match_reason);
    }

    public function test_a_similar_name_without_an_invoice_of_the_amount_gives_the_partner_only(): void
    {
        $partner = $this->partner('Ѓорѓи Михаилов');
        $this->sales($partner, '777.00');

        $line = $this->import([$this->in('10.00', 'uplata', ['name' => 'GORGI MIHAILOV'])])->first();

        $this->assertNull($line->sales_invoice_id);
        $this->assertSame($partner->id, $line->partner_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
    }

    public function test_only_the_amount_matching_exactly_one_invoice_is_check(): void
    {
        $invoice = $this->sales($this->partner(), '4321.00');

        $line = $this->import([$this->in('4321.00', 'uplata', ['name' => 'NEPOZNAT SUBJEKT DOO'])])->first();

        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
    }

    public function test_only_the_amount_matching_two_invoices_is_left_unclear(): void
    {
        $this->sales($this->partner('Едно'), '4321.00');
        $this->sales($this->partner('Две'), '4321.00');

        $line = $this->import([$this->in('4321.00', 'uplata', ['name' => 'NEPOZNAT SUBJEKT DOO'])])->first();

        $this->assertNull($line->sales_invoice_id);
        $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence);
    }

    // --- веќе внесено плаќање и повеќе ставки ---

    public function test_an_existing_bank_payment_of_the_same_amount_is_linked_as_check(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '400.00', '2026-03-04', 'bank', $this->admin->id);

        $line = $this->import([$this->in('400.00', 'fra '.$invoice->formattedNumber())])->first();

        $this->assertSame($payment->id, $line->sales_invoice_payment_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertStringContainsString('веќе внесеното', (string) $line->match_reason);
    }

    public function test_two_lines_of_one_import_cannot_spend_the_same_balance_twice(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');
        $text = 'fra '.$invoice->formattedNumber();

        $lines = $this->import([$this->in('800.00', $text), $this->in('800.00', $text, ['bankref' => '1000002'])]);

        $this->assertSame(MatchConfidence::SURE, $lines[0]->match_confidence);
        $this->assertSame(MatchConfidence::CHECK, $lines[1]->match_confidence);
        $this->assertStringContainsString('надминува', (string) $lines[1]->match_reason);
    }

    // --- што не се гледа ---

    public function test_foreign_currency_unconfirmed_and_other_company_invoices_are_never_matched(): void
    {
        $partner = $this->partner();
        $foreign = $this->sales($partner, '1000.00');
        $foreign->update(['currency' => 'EUR']);
        $draft = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id]);
        $other = Company::factory()->create();
        $otherPartner = Partner::factory()->for($other)->create();
        $theirs = $this->sales($otherPartner, '1000.00', '2026-03-01', $other);

        $line = $this->import([$this->in('1000.00', 'fra '.$foreign->formattedNumber().' '.$theirs->formattedNumber())])->first();

        $this->assertNull($line->sales_invoice_id);
        $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence);
        $this->assertNotNull($draft);
    }

    public function test_a_fully_paid_invoice_is_not_a_candidate(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');
        app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '1000.00', '2026-03-04', 'cash', $this->admin->id);

        $line = $this->import([$this->in('1000.00', 'fra '.$invoice->formattedNumber())])->first();

        $this->assertNull($line->sales_invoice_id);
    }

    public function test_a_matched_line_posts_as_a_payment_of_the_invoice(): void
    {
        $invoice = $this->sales($this->partner(), '1000.00');
        $statement = BankStatement::findOrFail(app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement(['lines' => [$this->in('1000.00', 'fra '.$invoice->formattedNumber())]]),
        ]])[0]->statementId);

        app(BankStatementPoster::class)->post($statement, $this->admin->id);

        $this->assertSame('0.00', $invoice->fresh(['lines', 'payments'])->balanceDue());
        $this->assertSame(1, $invoice->payments()->count());
    }
}
