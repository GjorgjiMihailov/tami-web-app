<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function confirmed(string $currency = 'EUR', string $rate = '61.500000'): SalesInvoice
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $this->user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => $currency, 'exchange_rate' => $rate,
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function pay(SalesInvoice $invoice, string $amount, string $method = 'bank', ?string $rate = null)
    {
        return app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), $amount, '2026-10-20', $method, $this->user->id, $rate);
    }

    private function counts(SalesInvoice $invoice): array
    {
        return [$invoice->payments()->count(), JournalEntryLine::count(), JournalEntry::count()];
    }

    public function test_a_foreign_payment_without_a_rate_is_refused_and_leaves_nothing_behind(): void
    {
        $invoice = $this->confirmed();
        $before = $this->counts($invoice);

        try {
            $this->pay($invoice, '1000.00');
            $this->fail('очекуван исклучок');
        } catch (InvalidInvoiceStateException $e) {
            $this->assertStringContainsString('курс', $e->getMessage());
        }

        $this->assertSame($before, $this->counts($invoice));
    }

    public function test_a_zero_or_negative_rate_is_refused(): void
    {
        $invoice = $this->confirmed();
        $before = $this->counts($invoice);

        foreach (['0', '0.000000', '-61.2'] as $rate) {
            try {
                $this->pay($invoice, '1000.00', 'bank', $rate);
                $this->fail("очекуван исклучок за курс {$rate}");
            } catch (InvalidInvoiceStateException) {
                // очекувано
            }
        }

        $this->assertSame($before, $this->counts($invoice));
    }

    public function test_a_foreign_payment_in_cash_is_refused(): void
    {
        $invoice = $this->confirmed();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->pay($invoice, '1000.00', 'cash', '61.200000');
    }

    public function test_a_payment_above_the_balance_is_still_refused(): void
    {
        $invoice = $this->confirmed();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->pay($invoice, '1000.01', 'bank', '61.200000');
    }

    public function test_a_foreign_payment_is_posted_in_group_11_with_1030_and_stores_the_rate(): void
    {
        $invoice = $this->confirmed();

        $payment = $this->pay($invoice, '1000.00', 'bank', '61.200000');

        $this->assertSame(0, bccomp('61.2', (string) $payment->fresh()->exchange_rate, 6));
        $entry = JournalEntry::where('company_id', $invoice->company_id)->latest('id')->first();
        $this->assertSame('11', JournalGroup::find($entry->journal_group_id)->code);

        $codes = $entry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))->all();
        $this->assertEqualsCanonicalizing(['1030 D 61200.00', '4750 D 300.00', '1211 C 61500.00'], $codes);
        $this->assertSame('0.00', $invoice->fresh(['lines', 'payments'])->balanceDue());
    }

    public function test_a_missing_1030_rolls_everything_back(): void
    {
        $invoice = $this->confirmed();
        Account::where('company_id', $invoice->company_id)->where('code', '1030')->delete();
        $before = $this->counts($invoice);

        try {
            $this->pay($invoice, '1000.00', 'bank', '61.200000');
            $this->fail('очекуван исклучок');
        } catch (InvalidInvoiceStateException) {
            // очекувано
        }

        $this->assertSame($before, $this->counts($invoice));
    }

    public function test_a_denar_payment_is_unchanged(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $this->user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-10-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00']);
        $invoice = app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);

        $payment = $this->pay($invoice, '1000.00', 'bank', '99.9');

        $this->assertNull($payment->fresh()->exchange_rate);
        $entry = JournalEntry::where('company_id', $company->id)->latest('id')->first();
        $this->assertSame('10', JournalGroup::find($entry->journal_group_id)->code);
        $codes = $entry->lines()->with('account')->get()->map(fn ($l) => $l->account->code)->all();
        $this->assertEqualsCanonicalizing(['1000', '1200'], $codes);
    }
}
