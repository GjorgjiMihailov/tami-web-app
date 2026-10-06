<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Posting\PostedInvoiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePaymentSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01'], $attrs));
        $invoice->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function paymentEntry(PurchaseInvoice $invoice): JournalEntry
    {
        return JournalEntry::where('company_id', $invoice->company_id)->where('id', '!=', $invoice->journal_entry_id)->with('lines.account')->latest('id')->firstOrFail();
    }

    public function test_a_bank_payment_posts_2200_against_1000(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '60.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $entry = $this->paymentEntry($invoice);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '2200')->debit);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1000')->credit);
        $this->assertSame('Payment for purchase bill Добавувач #77', $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
    }

    public function test_a_cash_payment_posts_to_1020(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'cash', User::factory()->create()->id);

        $this->assertNotNull($this->paymentEntry($invoice)->lines->firstWhere('account.code', '1020'));
    }

    public function test_an_import_invoice_is_paid_against_2210(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $invoice = $this->confirmed($company, ['is_import' => true, 'warehouse_id' => $warehouse->id]);

        $this->assertSame('2210', PostedInvoiceAccounts::payable($invoice->fresh())->code);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $this->assertSame('100.00', (string) $this->paymentEntry($invoice)->lines->firstWhere('account.code', '2210')->debit);
    }

    public function test_a_payment_on_an_invoice_booked_on_220_before_the_schemes_closes_220(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Стар']);
        $legacy = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-01-10', 'status' => 'confirmed', 'supplier_invoice_number' => '5']);
        $legacy->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'Стара', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '99'], ['name' => 'Автоматски', 'sort_order' => 99]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-01-10', 'description' => 'Purchase bill Стар #5', 'created_by' => User::factory()->create()->id]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '100.00', 'credit' => '0', 'description' => 'Purchase bill Стар #5']);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '220')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '0', 'credit' => '100.00', 'description' => 'Purchase bill Стар #5']);
        $legacy->update(['journal_entry_id' => $entry->id]);

        $this->assertSame('220', PostedInvoiceAccounts::payable($legacy->fresh())->code);

        app(PurchaseInvoiceService::class)->recordPayment($legacy->fresh(), '100.00', '2026-02-01', 'bank', User::factory()->create()->id);

        $payment = $this->paymentEntry($legacy);
        $this->assertSame('100.00', (string) $payment->lines->firstWhere('account.code', '220')->debit);
        $this->assertNull($payment->lines->firstWhere('account.code', '2200'));
    }

    public function test_a_draft_invoice_falls_back_to_2200(): void
    {
        $company = Company::factory()->create();
        $draft = PurchaseInvoice::factory()->for($company)->create();

        $this->assertSame('2200', PostedInvoiceAccounts::payable($draft)->code);
    }
}
