<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidInvoiceStateException;
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

class CreatePaymentRecordTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedSales(Company $company, string $unitPrice = '100.00'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $unitPrice, 'vat_rate' => '0']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
    }

    public function test_sales_payment_record_is_created_without_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedSales($company);
        $before = JournalEntry::count();

        $payment = app(SalesInvoiceService::class)->createPaymentRecord($invoice, '40.00', '2026-03-10', User::factory()->create()->id);

        $this->assertSame('40.00', (string) $payment->amount);
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('60.00', $invoice->fresh(['lines', 'payments'])->balanceDue());
    }

    public function test_sales_payment_record_cannot_exceed_the_balance(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedSales($company);

        $this->expectException(InvalidInvoiceStateException::class);

        app(SalesInvoiceService::class)->createPaymentRecord($invoice, '100.01', '2026-03-10', User::factory()->create()->id);
    }

    public function test_sales_payment_record_rejects_a_draft_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->expectException(InvalidInvoiceStateException::class);

        app(SalesInvoiceService::class)->createPaymentRecord($invoice, '10.00', '2026-03-10', User::factory()->create()->id);
    }

    public function test_sales_payment_record_rejects_a_foreign_currency_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedSales($company);
        $invoice->update(['currency' => 'EUR', 'exchange_rate' => '61.500000']);

        $this->expectException(InvalidInvoiceStateException::class);

        app(SalesInvoiceService::class)->createPaymentRecord($invoice->fresh(), '10.00', '2026-03-10', User::factory()->create()->id);
    }

    public function test_purchase_payment_record_is_created_without_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $expense = Account::where('company_id', $company->id)->where('code', '462')->firstOrFail();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $user->id);
        $before = JournalEntry::count();

        $payment = app(PurchaseInvoiceService::class)->createPaymentRecord($confirmed, '100.00', '2026-03-10', $user->id);

        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('0.00', $confirmed->fresh(['lines', 'payments'])->balanceDue());
    }
}
