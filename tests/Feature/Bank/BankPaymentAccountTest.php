<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankPaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bank_payment_on_a_sales_invoice_posts_to_1000_not_100(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'bank', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '1000'));
        $this->assertNull($entry->lines->firstWhere('account.code', '100'));
    }

    public function test_a_bank_payment_on_a_purchase_invoice_posts_to_1000_not_100(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $expense = Account::where('company_id', $company->id)->where('code', '462')->firstOrFail();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(PurchaseInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'bank', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '1000'));
        $this->assertNull($entry->lines->firstWhere('account.code', '100'));
    }

    public function test_cash_payments_stay_on_102(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'cash', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '102'));
    }

    public function test_the_account_scope_keeps_only_analytical_accounts(): void
    {
        $company = Company::factory()->create();

        $this->assertTrue(Account::where('company_id', $company->id)->analytical()->where('code', '1000')->exists());
        $this->assertFalse(Account::where('company_id', $company->id)->analytical()->where('code', '100')->exists());
        $this->assertSame('1000', Account::BANK_CODE);
    }
}
