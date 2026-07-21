<?php

namespace Tests\Unit;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseInvoiceService::class);
    }

    // NOTE: CompanyObserver already auto-seeds the full official chart (all
    // 428 accounts, including every code below) on Company::factory()->create().
    // firstOrCreate() keeps this helper's intent (guarantee these codes
    // exist) without violating the accounts(company_id, code) unique
    // constraint by double-inserting them.
    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '130', 'name' => 'Input VAT'],
            ['code' => '220', 'name' => 'AP'],
            ['code' => '660', 'name' => 'Inventory Asset'],
            ['code' => '100', 'name' => 'Bank'],
            ['code' => '102', 'name' => 'Cash'],
            ['code' => '462', 'name' => 'Services expense'],
        ] as $account) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $account['code']],
                $account
            );
        }
    }

    public function test_confirming_an_expense_only_bill_posts_expense_account_and_ap(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_entry_id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(3, $entry->lines);

        $expense = $entry->lines->firstWhere('account.code', '462');
        $vat = $entry->lines->firstWhere('account.code', '130');
        $ap = $entry->lines->firstWhere('account.code', '220');

        $this->assertSame('1000.00', (string) $expense->debit);
        $this->assertSame('180.00', (string) $vat->debit);
        $this->assertSame('1180.00', (string) $ap->credit);
    }

    public function test_confirming_an_item_line_receives_stock_into_inventory_asset(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
        ]);
        $line = $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('10.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);
        $this->assertSame('50.0000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->average_cost);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');
        $this->assertSame('500.00', (string) $inventoryAsset->debit);

        $this->assertSame($line->fresh()->stock_movement_id, \App\Models\StockMovement::where('item_id', $item->id)->where('type', 'receipt')->first()->id);
    }

    public function test_non_deductible_vat_is_folded_into_the_line_debit_instead_of_split_to_130(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Entertainment', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '130'));

        $expense = $entry->lines->firstWhere('account.code', '462');
        $ap = $entry->lines->firstWhere('account.code', '220');
        $this->assertSame('1180.00', (string) $expense->debit);
        $this->assertSame('1180.00', (string) $ap->credit);
    }

    public function test_confirming_skips_vat_when_company_is_not_vat_registered(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '130'));
    }

    public function test_confirming_requires_at_least_one_line(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_already_confirmed_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_item_line_without_a_warehouse_throws(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['warehouse_id' => null]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice->fresh(), $user->id);
    }

    public function test_cancelling_a_confirmed_invoice_reverses_gl_and_stock(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $cancelled = $this->service->cancel($confirmed, $user->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);

        $reversal = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines')->first();
        $this->assertNotNull($reversal);

        $originalTotalDebit = $confirmed->journalEntry->lines->sum('debit');
        $reversalTotalCredit = $reversal->lines->sum('credit');
        $this->assertSame((string) $originalTotalDebit, (string) $reversalTotalCredit);
    }

    public function test_cancelling_a_draft_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($invoice, $user->id);
    }

    public function test_cancelling_an_invoice_with_a_payment_throws(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);
        // NOTE: PurchaseInvoiceService::recordPayment() doesn't exist until Task 6,
        // so this creates the payment directly via the existing payments() relation
        // (PurchaseInvoicePayment model/table from Task 3) rather than through the
        // service, to keep this test self-contained within Task 5's scope.
        $confirmed->payments()->create([
            'amount' => '50.00',
            'payment_date' => '2026-03-05',
            'payment_method' => 'bank',
            'created_by' => $user->id,
        ]);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($confirmed->fresh(), $user->id);
    }

    public function test_cancelling_throws_a_clear_error_when_received_stock_was_already_used_elsewhere(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        // Sell off 6 of the 10 received units via a plain issue, leaving only 4 on hand.
        app(\App\Services\Inventory\StockMovementService::class)->issue($item, $warehouse, '6', '2026-03-02', $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($confirmed->fresh(), $user->id);
    }

    public function test_recording_a_payment_posts_ap_debit_and_bank_credit(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $payment = $this->service->recordPayment($confirmed, '60.00', '2026-03-10', 'bank', $user->id);

        $this->assertSame('60.00', (string) $payment->amount);
        $this->assertSame('partially_paid', $confirmed->fresh(['lines', 'payments'])->paymentStatus());

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $bank = $entry->lines->firstWhere('account.code', '100');
        $ap = $entry->lines->firstWhere('account.code', '220');

        $this->assertSame('60.00', (string) $bank->credit);
        $this->assertSame('60.00', (string) $ap->debit);
    }

    public function test_recording_a_cash_payment_credits_the_cash_account(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->service->recordPayment($confirmed, '100.00', '2026-03-10', 'cash', $user->id);

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $cash = $entry->lines->firstWhere('account.code', '102');
        $this->assertSame('100.00', (string) $cash->credit);
    }

    public function test_payment_cannot_exceed_the_remaining_balance(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($confirmed, '150.00', '2026-03-10', 'bank', $user->id);
    }

    public function test_payment_on_a_draft_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($invoice, '10.00', '2026-03-10', 'bank', $user->id);
    }
}
