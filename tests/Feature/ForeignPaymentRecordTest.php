<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignPaymentRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function confirmed(string $currency = 'EUR'): SalesInvoice
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $this->user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => $currency,
            'exchange_rate' => $currency === 'MKD' ? '1' : '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function record(SalesInvoice $invoice, string $amount, ?string $rate = '61.200000')
    {
        return app(SalesInvoiceService::class)->createForeignPaymentRecord($invoice->fresh(), $amount, '2026-10-20', (string) $rate, $this->user->id, 'Извод');
    }

    public function test_it_creates_the_payment_and_returns_the_lines_without_a_journal_entry(): void
    {
        $invoice = $this->confirmed();
        $entries = JournalEntry::count();

        [$payment, $lines] = $this->record($invoice, '1000.00');

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame('61.200000', (string) $payment->fresh()->exchange_rate);
        $this->assertSame('1030', $lines[0]->account->code);
        $this->assertSame('debit', $lines[0]->side);
        $this->assertSame('61200.00', $lines[0]->amount);
        $this->assertSame('1211', $lines[1]->account->code);
        $this->assertSame('61500.00', $lines[1]->amount);
    }

    public function test_a_denar_invoice_is_refused(): void
    {
        $invoice = $this->confirmed('MKD');

        $this->expectException(InvalidInvoiceStateException::class);

        $this->record($invoice, '100.00');
    }

    public function test_a_missing_or_zero_rate_is_refused(): void
    {
        $invoice = $this->confirmed();

        foreach (['', '0', '-1'] as $rate) {
            try {
                $this->record($invoice, '100.00', $rate);
                $this->fail("очекуван исклучок за курс '{$rate}'");
            } catch (InvalidInvoiceStateException) {
                // очекувано
            }
        }

        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_two_partial_receipts_close_the_receivable_exactly(): void
    {
        $invoice = $this->confirmed();

        [, $first] = $this->record($invoice, '400.00', '61.300000');
        [, $second] = $this->record($invoice, '600.00', '61.700000');

        $closed = collect([...$first, ...$second])
            ->filter(fn ($l) => $l->account->code === '1211' && $l->side === 'credit')
            ->reduce(fn (string $c, $l) => bcadd($c, $l->amount, 2), '0.00');

        $this->assertSame('61500.00', $closed);
        $this->assertSame('0.00', $invoice->fresh()->balanceDue());
    }
}
