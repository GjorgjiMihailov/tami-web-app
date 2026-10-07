<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceImportCogsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['is_vat_registered' => true]);
        $this->item = Item::factory()->for($this->company)->create(['type' => 'product']);
        $this->warehouse = Warehouse::factory()->for($this->company)->create();
    }

    private function buy(bool $import, string $quantity, string $price): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => '2026-03-01', 'is_import' => $import,
        ]);
        $invoice->lines()->create(['item_id' => $this->item->id, 'description' => 'Стока', 'quantity' => $quantity, 'unit_price' => $price, 'vat_rate' => '18.00']);
        app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function sell(string $quantity): SalesInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $sale = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => '2026-03-05']);
        $sale->lines()->create(['item_id' => $this->item->id, 'description' => 'Стока', 'quantity' => $quantity, 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        return app(SalesInvoiceService::class)->confirm($sale->fresh(), User::factory()->create()->id);
    }

    private function codes(SalesInvoice $confirmed): array
    {
        return $confirmed->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))
            ->all();
    }

    public function test_domestic_goods_are_taken_off_6600(): void
    {
        $this->buy(false, '10', '50.00');

        $codes = $this->codes($this->sell('4'));

        $this->assertContains('7010 D 200.00', $codes);
        $this->assertContains('6600 C 200.00', $codes);
        $this->assertNotContains('6601', array_map(fn ($c) => explode(' ', $c)[0], $codes));
    }

    public function test_imported_goods_are_taken_off_6601(): void
    {
        $this->buy(true, '10', '50.00');

        $codes = $this->codes($this->sell('4'));

        $this->assertContains('7010 D 200.00', $codes);
        $this->assertContains('6601 C 200.00', $codes);
        $this->assertNotContains('6600', array_map(fn ($c) => explode(' ', $c)[0], $codes));
    }

    public function test_mixed_stock_is_split_in_proportion(): void
    {
        $this->buy(false, '10', '10.00'); // 100 домашно
        $this->buy(true, '10', '20.00');  // 200 од увоз → вкупно 300, просек 15

        $codes = $this->codes($this->sell('10')); // 150: 50 домашно + 100 од увоз

        $this->assertContains('7010 D 150.00', $codes);
        $this->assertContains('6600 C 50.00', $codes);
        $this->assertContains('6601 C 100.00', $codes);
    }

    public function test_the_entry_balances_to_the_cent_for_an_awkward_split(): void
    {
        $this->buy(false, '7', '3.33');
        $this->buy(true, '5', '4.17');

        $confirmed = $this->sell('9');
        $lines = $confirmed->journalEntry->lines;

        $this->assertSame(0, bccomp((string) $lines->sum('debit'), (string) $lines->sum('credit'), 2));
    }
}
