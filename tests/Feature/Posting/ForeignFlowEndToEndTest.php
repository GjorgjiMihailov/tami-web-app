<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignFlowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function balance(Company $company, string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $company->id)->where('code', $code))
            ->get()
            ->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');
    }

    public function test_mixed_foreign_invoice_two_payments_at_different_rates_closes_to_zero(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '1.00', '2026-09-01', $user->id);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'warehouse_id' => $warehouse->id,
            'currency' => 'EUR', 'exchange_rate' => '61.473300',
        ]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '1', 'unit_price' => '600.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '400.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        // Побарувањето е поделено: добра на 1210, услуги на 1211, ништо на 1200.
        $this->assertSame('0.00', $this->balance($company, '1200'));
        $this->assertSame('0.00', bcsub(bcadd($this->balance($company, '1210'), $this->balance($company, '1211'), 2), $this->receivableGross($confirmed), 2));

        $total = $confirmed->fresh(['lines', 'payments'])->grandTotal();
        $first = '700.00';
        $service->recordPayment($confirmed->fresh(), $first, '2026-10-10', 'bank', $user->id, '61.200000');
        $service->recordPayment($confirmed->fresh(), bcsub($total, $first, 2), '2026-10-20', 'bank', $user->id, '61.900000');

        $this->assertSame('0.00', $this->balance($company, '1210'));
        $this->assertSame('0.00', $this->balance($company, '1211'));
        $this->assertSame('0.00', $confirmed->fresh(['lines', 'payments'])->balanceDue());

        // 1030 е збирот на приливите по курсот на денот; 4750/7750 е нето разликата.
        $inflows = bcadd(
            bcmul('700.00', '61.2', 2),
            bcmul(bcsub($total, '700.00', 2), '61.9', 2),
            2
        );
        $this->assertSame(0, bccomp($inflows, $this->balance($company, '1030'), 2));
        // Приливот + загубата − добивката го дава затворениот побарувачки износ.
        $closed = bcadd(bcadd($this->balance($company, '1030'), $this->balance($company, '4750'), 2), $this->balance($company, '7750'), 2);
        $this->assertSame($this->receivableGross($confirmed), $closed);

        foreach (JournalEntry::where('company_id', $company->id)->with('lines')->get() as $entry) {
            $debits = $entry->lines->reduce(fn (string $c, $l) => bcadd($c, (string) $l->debit, 2), '0.00');
            $credits = $entry->lines->reduce(fn (string $c, $l) => bcadd($c, (string) $l->credit, 2), '0.00');
            $this->assertSame(0, bccomp($debits, $credits, 2), "Налог {$entry->id}: должи {$debits}, побарува {$credits}");
        }
    }

    /** Денарскиот износ со кој фактурата го отворила побарувањето. */
    private function receivableGross(SalesInvoice $invoice): string
    {
        return $invoice->journalEntry->lines()->with('account')->get()
            ->filter(fn ($l) => in_array($l->account->code, ['1210', '1211'], true))
            ->reduce(fn (string $c, $l) => bcadd($c, (string) $l->debit, 2), '0.00');
    }
}
