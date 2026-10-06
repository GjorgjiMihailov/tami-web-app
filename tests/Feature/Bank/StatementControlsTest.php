<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementControlsTest extends TestCase
{
    use RefreshDatabase;

    private function statement(array $attrs = []): BankStatement
    {
        return BankStatement::factory()->create(array_merge([
            'opening_balance' => '1000.00',
            'closing_balance' => '1500.00',
            'statement_date' => '2026-03-05',
        ], $attrs));
    }

    private function line(BankStatement $statement, array $attrs = []): BankStatementLine
    {
        $account = Account::where('company_id', $statement->company_id)->where('code', '7400')->firstOrFail();

        return BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => LineDirection::IN,
            'amount' => '500.00',
            'kind' => LineKind::ACCOUNT,
            'account_id' => $account->id,
        ], $attrs));
    }

    public function test_difference_is_opening_plus_movement_minus_closing(): void
    {
        $this->assertSame('0.00', StatementControls::difference('1000.00', '1500.00', ['500.00']));
        $this->assertSame('-50.00', StatementControls::difference('1000.00', '1500.00', ['450.00']));
        $this->assertSame('0.00', StatementControls::difference('1000.00', '700.00', ['-300.00']));
        $this->assertNull(StatementControls::difference(null, '1500.00', ['500.00']));
        $this->assertNull(StatementControls::difference('1000.00', null, ['500.00']));
    }

    public function test_a_balanced_statement_has_no_problems(): void
    {
        $statement = $this->statement();
        $this->line($statement);

        $this->assertSame([], StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_empty_statement_is_a_problem(): void
    {
        $this->assertContains('Изводот нема ставки.', StatementControls::problems($this->statement()->fresh('lines')));
    }

    public function test_missing_balances_are_a_problem(): void
    {
        $statement = $this->statement(['opening_balance' => null]);
        $this->line($statement);

        $this->assertContains('Внесете почетна и крајна состојба.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_difference_is_a_problem(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['amount' => '450.00']);

        $problems = StatementControls::problems($statement->fresh('lines'));

        $this->assertContains('Почетна состојба + движење не е еднакво на крајната состојба (разлика −50,00).', $problems);
    }

    public function test_the_opening_must_equal_the_previous_booked_statements_closing(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create([
            'number' => 1, 'statement_date' => '2026-03-04', 'closing_balance' => '900.00',
            'opening_balance' => '0.00', 'status' => BankStatement::STATUS_BOOKED,
        ]);
        $statement = $this->statement(['company_id' => $company->id, 'number' => 2]);
        $this->line($statement);

        $problems = StatementControls::problems($statement->fresh('lines'));

        $this->assertContains('Почетната состојба (1.000,00) не е еднаква на крајната од извод 1 (900,00).', $problems);
    }

    public function test_the_chain_ignores_drafts_and_other_accounts_and_other_years(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['number' => 1, 'closing_balance' => '1.00', 'status' => BankStatement::STATUS_DRAFT]);
        BankStatement::factory()->for($company)->create(['number' => 1, 'account' => '999', 'closing_balance' => '2.00', 'status' => BankStatement::STATUS_BOOKED]);
        BankStatement::factory()->for($company)->create(['number' => 1, 'statement_date' => '2025-12-30', 'closing_balance' => '3.00', 'status' => BankStatement::STATUS_BOOKED]);
        $statement = $this->statement(['company_id' => $company->id, 'number' => 2]);
        $this->line($statement);

        $this->assertSame([], StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_line_in_another_year_is_a_problem(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['line_date' => '2025-12-31']);

        $this->assertContains('Ставка 1: датумот е од друга година од изводот.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_unclear_line_needs_an_account(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['kind' => LineKind::UNCLEAR, 'account_id' => null]);

        $this->assertContains('Ставка 1: изберете конто.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_heading_account_is_not_allowed(): void
    {
        $statement = $this->statement();
        $heading = Account::where('company_id', $statement->company_id)->where('code', '120')->firstOrFail();
        $this->line($statement, ['account_id' => $heading->id]);

        $this->assertContains('Ставка 1: контото 120 не е аналитичко.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_account_of_another_company_is_not_allowed(): void
    {
        $statement = $this->statement();
        $foreign = Account::where('company_id', Company::factory()->create()->id)->where('code', '7400')->firstOrFail();
        $this->line($statement, ['account_id' => $foreign->id]);

        $this->assertContains('Ставка 1: контото не постои во оваа фирма.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_invoice_of_another_company_is_refused(): void
    {
        $other = Company::factory()->create();
        $partner = Partner::factory()->for($other)->create();
        $invoice = SalesInvoice::factory()->for($other)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        $statement = $this->statement();
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id, 'amount' => '300.00']);

        $this->assertContains('Ставка 1: фактурата не е од оваа фирма.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_linked_payment_must_belong_to_the_chosen_invoice_and_be_unique(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $make = function (string $price) use ($company, $partner, $user) {
            $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
            $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);
            app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);

            return $invoice;
        };
        $a = $make('300.00');
        $b = $make('300.00');
        $paymentOfB = app(SalesInvoiceService::class)->recordPayment($b->fresh(), '300.00', '2026-03-04', 'bank', $user->id);
        $statement = $this->statement(['company_id' => $company->id]);

        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $a->id, 'sales_invoice_payment_id' => $paymentOfB->id, 'amount' => '300.00']);
        $this->assertContains('Ставка 1: плаќањето не е на избраната фактура.', StatementControls::problems($statement->fresh('lines')));

        $statement->lines()->delete();
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $b->id, 'sales_invoice_payment_id' => $paymentOfB->id, 'amount' => '300.00']);
        $other = $this->statement(['company_id' => $company->id, 'number' => 9]);
        $this->line($other, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $b->id, 'sales_invoice_payment_id' => $paymentOfB->id, 'amount' => '300.00']);

        $this->assertContains('Ставка 1: плаќањето е веќе врзано за друга ставка на извод.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_two_lines_on_one_invoice_are_checked_together(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
        $statement = $this->statement(['company_id' => $company->id, 'closing_balance' => '1400.00']);
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id, 'amount' => '200.00', 'position' => 1]);
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id, 'amount' => '200.00', 'position' => 2]);

        $this->assertContains('Ставки 1, 2: заедно ја надминуваат фактурата (салдо 300,00).', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_invoice_payment_needs_an_invoice_within_its_balance(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
        $statement = $this->statement(['company_id' => $company->id]);

        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => null]);
        $this->assertContains('Ставка 1: изберете фактура.', StatementControls::problems($statement->fresh('lines')));

        $statement->lines()->delete();
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id, 'amount' => '500.00']);
        $this->assertContains('Ставка 1: износот го надминува салдото на фактурата (300,00).', StatementControls::problems($statement->fresh('lines')));
    }
}
