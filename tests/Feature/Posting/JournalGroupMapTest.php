<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\PayrollRun;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\JournalGroupRelocator;
use App\Support\JournalGroupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalGroupMapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_vat_registered' => true]);
        $this->user = User::factory()->create();
    }

    private function groupOf(JournalEntry $entry): string
    {
        return JournalGroup::find($entry->journal_group_id)->code;
    }

    private function confirmedSales(): SalesInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function confirmedPurchase(): PurchaseInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create([
            'account_id' => Account::where('company_id', $this->company->id)->where('code', '4620')->value('id'),
            'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00',
        ]);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function newestEntryGroup(): string
    {
        return $this->groupOf(JournalEntry::where('company_id', $this->company->id)->latest('id')->firstOrFail());
    }

    public function test_a_bank_payment_of_an_invoice_goes_to_group_10(): void
    {
        $invoice = $this->confirmedSales();
        app(SalesInvoiceService::class)->recordPayment($invoice, '50.00', '2026-03-10', 'bank', $this->user->id);

        $this->assertSame('10', $this->newestEntryGroup());
    }

    public function test_a_cash_payment_of_an_invoice_stays_in_group_99(): void
    {
        $invoice = $this->confirmedSales();
        app(SalesInvoiceService::class)->recordPayment($invoice, '50.00', '2026-03-10', 'cash', $this->user->id);

        $this->assertSame('99', $this->newestEntryGroup());
    }

    public function test_a_bank_payment_of_a_purchase_bill_goes_to_group_10(): void
    {
        $invoice = $this->confirmedPurchase();
        app(PurchaseInvoiceService::class)->recordPayment($invoice, '50.00', '2026-03-10', 'bank', $this->user->id);

        $this->assertSame('10', $this->newestEntryGroup());
    }

    public function test_the_relocator_moves_old_group_99_payroll_and_bank_payments_and_statements(): void
    {
        $legacy = JournalGroup::create(['company_id' => $this->company->id, 'code' => '99', 'name' => 'Автоматски (фактури)', 'sort_order' => 99]);
        $account = fn (string $code) => Account::where('company_id', $this->company->id)->where('code', $code)->value('id');

        $make = function (JournalGroup $group, string $date, string $description, string $code) use ($account): JournalEntry {
            $entry = JournalEntry::create([
                'company_id' => $this->company->id, 'journal_group_id' => $group->id,
                'entry_date' => $date, 'description' => $description, 'created_by' => $this->user->id,
            ]);
            $entry->lines()->create(['account_id' => $account($code), 'description' => 'x', 'line_date' => $date, 'debit' => 10, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $account('4620'), 'description' => 'x', 'line_date' => $date, 'debit' => 0, 'credit' => 10]);

            return $entry;
        };

        $payroll = $make($legacy, '2026-03-31', 'Плата 3/2026', '421');
        $reversal = $make($legacy, '2026-03-31', 'Сторно: Плата 3/2026', '421');
        $bankPayment = $make($legacy, '2026-03-10', 'Payment for invoice 2026/1', '100');
        $cashPayment = $make($legacy, '2026-03-11', 'Payment for invoice 2026/2', '1020');
        $other = $make($legacy, '2026-03-12', 'Нешто друго', '421');

        PayrollRun::factory()->create([
            'company_id' => $this->company->id, 'status' => PayrollRun::CONFIRMED,
            'journal_entry_id' => $payroll->id,
        ]);

        // Извод на втора денарска сметка во група 11 → мора во 10; девизен во 12 → 11.
        $second = JournalGroup::create(['company_id' => $this->company->id, 'code' => '11', 'name' => 'Извод — втора', 'sort_order' => 10]);
        $third = JournalGroup::create(['company_id' => $this->company->id, 'code' => '12', 'name' => 'Извод — девизна', 'sort_order' => 10]);
        $denar = BankStatement::factory()->for($this->company)->create(['status' => 'booked']);
        $denarEntry = $make($second, '2026-03-05', 'Извод бр. 1', '1000');
        $denar->update(['journal_entry_id' => $denarEntry->id]);
        $foreign = BankStatement::factory()->for($this->company)->foreign()->create(['status' => 'booked']);
        $foreignEntry = $make($third, '2026-03-06', 'Извод бр. 1 девизен', '1000');
        $foreign->update(['journal_entry_id' => $foreignEntry->id]);

        $lines = fn () => DB::table('journal_entry_lines')->selectRaw('SUM(debit) d, SUM(credit) c, COUNT(*) n')->first();
        $before = $lines();

        $counts = app(JournalGroupRelocator::class)->run();

        $this->assertSame(['payroll' => 2, 'payments' => 1, 'statements' => 2], $counts);

        $codeOf = fn (JournalEntry $e) => $this->groupOf($e->fresh());
        $this->assertSame('50', $codeOf($payroll));
        $this->assertSame('50', $codeOf($reversal));
        $this->assertSame('10', $codeOf($bankPayment));
        $this->assertSame('99', $codeOf($cashPayment));
        $this->assertSame('99', $codeOf($other));
        $this->assertSame('10', $codeOf($denarEntry));
        $this->assertSame('11', $codeOf($foreignEntry));

        $this->assertSame('50-0001', $payroll->fresh()->load('journalGroup')->displayNumber());
        $this->assertSame('50-0002', $reversal->fresh()->load('journalGroup')->displayNumber());

        $after = $lines();
        $this->assertEquals($before->d, $after->d);
        $this->assertEquals($before->c, $after->c);
        $this->assertEquals($before->n, $after->n);

        $again = app(JournalGroupRelocator::class)->run();
        $this->assertSame(['payroll' => 0, 'payments' => 0, 'statements' => 0], $again);
    }

    public function test_the_group_map_names_every_code(): void
    {
        foreach (['00', '10', '11', '20', '30', '50', '51', '52', '53', '90', '99'] as $code) {
            $this->assertNotSame('', JournalGroupCodes::name($code));
        }
    }
}
