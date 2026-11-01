<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignStatementControlsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => false]);
    }

    private function invoice(string $currency = 'EUR', ?Company $company = null): SalesInvoice
    {
        $company ??= $this->company;
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => Partner::factory()->for($company)->create()->id, 'invoice_date' => '2026-10-01',
            'currency' => $currency, 'exchange_rate' => $currency === 'MKD' ? '1' : '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function statement(string $opening, string $closing, array $lines): BankStatement
    {
        $statement = BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'opening_balance' => $opening,
            'closing_balance' => $closing, 'statement_date' => '2026-10-20', 'number' => 1,
        ]);

        foreach ($lines as $attrs) {
            BankStatementLine::factory()->for($statement)->create(array_merge(['line_date' => '2026-10-20'], $attrs));
        }

        return $statement->fresh();
    }

    private function receiptAttrs(SalesInvoice $invoice, string $amount = '1000.00', ?string $rate = '61.200000'): array
    {
        return ['direction' => LineDirection::IN, 'amount' => $amount, 'exchange_rate' => $rate, 'kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id];
    }

    private function saleAttrs(string $amount = '1000.00', ?string $rate = '61.500000'): array
    {
        return ['direction' => LineDirection::OUT, 'amount' => $amount, 'exchange_rate' => $rate, 'kind' => LineKind::CURRENCY_SALE];
    }

    public function test_a_valid_foreign_statement_has_no_problems(): void
    {
        $statement = $this->statement('0.00', '0.00', [$this->receiptAttrs($this->invoice()), $this->saleAttrs()]);

        $this->assertSame([], StatementControls::problems($statement));
    }

    public function test_a_line_that_is_neither_a_receipt_nor_a_sale_is_refused(): void
    {
        $account = \App\Models\Account::where('company_id', $this->company->id)->analytical()->first();
        $statement = $this->statement('0.00', '100.00', [
            ['direction' => LineDirection::IN, 'amount' => '100.00', 'kind' => LineKind::ACCOUNT, 'account_id' => $account->id],
        ]);

        $this->assertStringContainsString('само наплата на фактура и продажба на девиза', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_sale_in_the_wrong_direction_is_refused(): void
    {
        $statement = $this->statement('0.00', '1000.00', [array_merge($this->saleAttrs(), ['direction' => LineDirection::IN])]);

        $this->assertStringContainsString('само наплата на фактура и продажба на девиза', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_missing_rate_is_refused(): void
    {
        $statement = $this->statement('1000.00', '0.00', [$this->saleAttrs('1000.00', null)]);

        $this->assertStringContainsString('внесете курс', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_receipt_for_an_invoice_in_another_currency_is_refused(): void
    {
        $usd = $this->invoice('USD');
        $statement = $this->statement('0.00', '1000.00', [$this->receiptAttrs($usd)]);

        $this->assertStringContainsString('фактурата е во друга валута', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_receipt_for_a_denar_invoice_is_refused(): void
    {
        $statement = $this->statement('0.00', '1000.00', [$this->receiptAttrs($this->invoice('MKD'))]);

        $this->assertStringContainsString('фактурата е во друга валута', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_receipt_above_the_invoice_balance_is_refused(): void
    {
        $statement = $this->statement('0.00', '1500.00', [$this->receiptAttrs($this->invoice(), '1500.00')]);

        $this->assertStringContainsString('надминува салдото', implode(' ', StatementControls::problems($statement)));
    }

    public function test_another_companys_invoice_is_refused(): void
    {
        $other = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => false]);
        $statement = $this->statement('0.00', '1000.00', [$this->receiptAttrs($this->invoice('EUR', $other))]);

        $this->assertStringContainsString('не е од оваа фирма', implode(' ', StatementControls::problems($statement)));
    }

    public function test_the_balance_control_works_in_the_statements_currency(): void
    {
        $statement = $this->statement('500.00', '100.00', [$this->saleAttrs('300.00')]);

        $this->assertStringContainsString('не е еднакво', implode(' ', StatementControls::problems($statement)));
    }
}
