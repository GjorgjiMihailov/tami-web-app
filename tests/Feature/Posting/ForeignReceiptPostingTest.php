<?php

namespace Tests\Feature\Posting;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\ForeignReceiptPosting;
use App\Support\Posting\PostingLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignReceiptPostingTest extends TestCase
{
    use RefreshDatabase;

    /** Потврдена девизна фактура: $goods и $services се EUR износи без ДДВ (0 = нема таков ред). */
    private function posted(string $goods, string $services, string $rate, bool $vat = false): SalesInvoice
    {
        $company = Company::factory()->create(['is_vat_registered' => $vat]);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => $rate,
        ]);
        $vatRate = $vat ? '18.00' : '0.00';
        $treatment = $vat ? 'standard' : 'export';

        if (bccomp($goods, '0', 2) > 0) {
            $warehouse = Warehouse::factory()->for($company)->create();
            $item = Item::factory()->for($company)->create(['type' => 'product']);
            app(StockMovementService::class)->receipt($item, $warehouse, '10', '1.00', '2026-09-01', $user->id);
            $invoice->update(['warehouse_id' => $warehouse->id]);
            $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '1', 'unit_price' => $goods, 'vat_rate' => $vatRate, 'vat_treatment' => $treatment]);
        }

        if (bccomp($services, '0', 2) > 0) {
            $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $services, 'vat_rate' => $vatRate, 'vat_treatment' => $treatment]);
        }

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
    }

    /** @param list<PostingLine> $lines */
    private function codes(array $lines): array
    {
        return array_map(fn (PostingLine $l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount, $lines);
    }

    /** @param list<PostingLine> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $l) {
            if ($l->side === 'debit') {
                $debit = bcadd($debit, $l->amount, 2);
            } else {
                $credit = bcadd($credit, $l->amount, 2);
            }
        }

        $this->assertSame($debit, $credit);
    }

    private function lines(SalesInvoice $invoice, string $amount, string $before, string $rate): array
    {
        return app(ForeignReceiptPosting::class)->lines($invoice->fresh(), $amount, $before, $rate, 'Payment for invoice');
    }

    public function test_a_loss_goes_to_4750(): void
    {
        $lines = $this->lines($this->posted('0', '1000.00', '61.500000'), '1000.00', '0.00', '61.200000');

        $this->assertEqualsCanonicalizing(['1030 D 61200.00', '4750 D 300.00', '1211 C 61500.00'], $this->codes($lines));
        $this->assertBalanced($lines);
    }

    public function test_a_gain_goes_to_7750(): void
    {
        $lines = $this->lines($this->posted('0', '1000.00', '61.500000'), '1000.00', '0.00', '61.800000');

        $this->assertEqualsCanonicalizing(['1030 D 61800.00', '1211 C 61500.00', '7750 C 300.00'], $this->codes($lines));
        $this->assertBalanced($lines);
    }

    public function test_equal_rate_has_no_difference_line(): void
    {
        $lines = $this->lines($this->posted('0', '1000.00', '61.500000'), '1000.00', '0.00', '61.500000');

        $this->assertEqualsCanonicalizing(['1030 D 61500.00', '1211 C 61500.00'], $this->codes($lines));
    }

    public function test_a_mixed_invoice_closes_both_receivables_proportionally(): void
    {
        $lines = $this->lines($this->posted('600.00', '400.00', '61.500000'), '400.00', '0.00', '61.500000');

        $this->assertEqualsCanonicalizing(['1030 D 24600.00', '1210 C 14760.00', '1211 C 9840.00'], $this->codes($lines));
        $this->assertBalanced($lines);
    }

    public function test_three_partial_payments_close_every_receivable_to_exactly_zero(): void
    {
        $invoice = $this->posted('600.00', '400.00', '61.473300', true);
        $debits = $invoice->journalEntry->lines()->with('account')->get()
            ->filter(fn ($l) => in_array($l->account->code, ['1210', '1211'], true))
            ->mapWithKeys(fn ($l) => [$l->account->code => (string) $l->debit]);
        $this->assertCount(2, $debits);

        $credits = ['1210' => '0.00', '1211' => '0.00'];
        $before = '0.00';

        foreach ([['400.00', '61.20'], ['400.00', '61.91'], ['380.00', '61.47']] as [$amount, $rate]) {
            $lines = $this->lines($invoice, $amount, $before, $rate);
            $this->assertBalanced($lines);

            foreach ($lines as $l) {
                if (isset($credits[$l->account->code])) {
                    $credits[$l->account->code] = bcadd($credits[$l->account->code], $l->amount, 2);
                }
            }

            $before = bcadd($before, $amount, 2);
        }

        $this->assertSame($debits['1210'], $credits['1210']);
        $this->assertSame($debits['1211'], $credits['1211']);
    }

    public function test_the_1030_line_carries_the_payment_day_rate_and_the_receivable_line_the_invoice_rate(): void
    {
        $lines = collect($this->lines($this->posted('0', '1000.00', '61.500000'), '1000.00', '0.00', '61.200000'))
            ->keyBy(fn (PostingLine $l) => $l->account->code);

        $this->assertSame('EUR', $lines['1030']->currencyCode);
        $this->assertSame('1000.00', $lines['1030']->foreignAmount);
        $this->assertSame(0, bccomp('61.2', $lines['1030']->exchangeRate, 6));
        $this->assertSame('1000.00', $lines['1211']->foreignAmount);
        $this->assertSame(0, bccomp('61.5', $lines['1211']->exchangeRate, 6));
        $this->assertNull($lines['4750']->foreignAmount);
    }

    public function test_it_refuses_when_the_company_has_no_1030(): void
    {
        $invoice = $this->posted('0', '1000.00', '61.500000');
        Account::where('company_id', $invoice->company_id)->where('code', '1030')->delete();

        $this->expectException(InvalidInvoiceStateException::class);
        $this->expectExceptionMessage('1030');

        $this->lines($invoice, '1000.00', '0.00', '61.200000');
    }
}
