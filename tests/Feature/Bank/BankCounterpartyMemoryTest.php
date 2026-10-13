<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankCounterpartyMemoryTest extends TestCase
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

    private function konto(string $code = '7400'): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    /**
     * Извод со една исплата на конто, за таа другата страна.
     *
     * @param  array<string, mixed>  $line
     */
    private function statementWith(array $line, ?Company $company = null, int $number = 1): BankStatement
    {
        $company ??= $this->company;

        $statement = BankStatement::factory()->for($company)->create([
            // Ланец: почетната е крајната на претходниот извод (по 10.00 по извод).
            'number' => $number, 'statement_date' => '2026-03-05',
            'opening_balance' => (($number - 1) * 10).'.00', 'closing_balance' => ($number * 10).'.00',
        ]);

        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => LineDirection::IN,
            'amount' => '10.00',
            'kind' => LineKind::ACCOUNT,
            'account_id' => $this->konto()->id,
            'counterparty_account' => '100000000000002',
            'counterparty_name' => 'TEST BANKA AD',
        ], $line));

        return $statement->fresh();
    }

    private function book(BankStatement $statement): void
    {
        app(BankStatementPoster::class)->post($statement, $this->admin->id);
    }

    public function test_booking_remembers_the_account_and_the_partner(): void
    {
        $partner = Partner::factory()->for($this->company)->create();

        $this->book($this->statementWith(['partner_id' => $partner->id]));

        $record = BankCounterparty::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('100000000000002', $record->account_key);
        $this->assertSame($this->konto()->id, $record->account_id);
        $this->assertSame($partner->id, $record->partner_id);
        $this->assertSame('TEST BANKA AD', $record->name_seen);
        $this->assertNotNull($record->last_used_at);
    }

    public function test_an_invoice_payment_remembers_the_partner_but_never_touches_the_account(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        BankCounterparty::create([
            'company_id' => $this->company->id, 'account_key' => '100000000000002', 'account_id' => $this->konto()->id,
        ]);

        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        $this->book($this->statementWith([
            'kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id,
        ]));

        $record = BankCounterparty::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($partner->id, $record->partner_id);
        $this->assertSame($this->konto()->id, $record->account_id);
    }

    public function test_a_later_booking_with_another_account_replaces_the_remembered_one(): void
    {
        $this->book($this->statementWith([]));
        $other = $this->konto('7410');

        $this->book($this->statementWith(['account_id' => $other->id], null, 2));

        $this->assertSame($other->id, BankCounterparty::where('company_id', $this->company->id)->firstOrFail()->account_id);
        $this->assertSame(1, BankCounterparty::count());
    }

    public function test_a_line_without_a_counterparty_account_is_skipped(): void
    {
        $this->book($this->statementWith(['counterparty_account' => null, 'counterparty_name' => null]));

        $this->assertSame(0, BankCounterparty::count());
    }

    public function test_a_dashed_spelling_is_the_same_record(): void
    {
        $this->book($this->statementWith(['counterparty_account' => '100000000000002']));
        $this->book($this->statementWith(['counterparty_account' => '100-0000000000-02'], null, 2));

        $this->assertSame(1, BankCounterparty::count());
    }

    public function test_the_memory_is_kept_per_company(): void
    {
        $this->book($this->statementWith([]));
        $other = Company::factory()->create();
        $account = Account::where('company_id', $other->id)->where('code', '7400')->firstOrFail();

        $statement = $this->statementWith(['account_id' => $account->id], $other);
        $this->book($statement);

        $this->assertSame(2, BankCounterparty::count());
        $this->assertSame($account->id, BankCounterparty::where('company_id', $other->id)->firstOrFail()->account_id);
        $this->assertSame($this->konto()->id, BankCounterparty::where('company_id', $this->company->id)->firstOrFail()->account_id);
    }

    public function test_a_draft_remembers_nothing(): void
    {
        $this->statementWith([]);

        $this->assertSame(0, BankCounterparty::count());
    }
}
