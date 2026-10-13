<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemForm;
use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Цена со и без ДДВ на артикл: се пишува во едното поле, другото се пополнува.
 * Цената што е внесена со ДДВ се чува онаква каква што е (исто како на ставка од
 * фактура), за „120,00“ да не се врати како „119,99“ по заокружувањето на нето.
 */
class ItemGrossPriceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->company = Company::factory()->create(['is_vat_registered' => true]);
    }

    private function form(?Item $item = null)
    {
        return Livewire::test(ItemForm::class, ['company' => $this->company, 'item' => $item]);
    }

    public function test_typing_the_net_selling_price_fills_the_gross(): void
    {
        $this->form()
            ->set('vatRate', '18.00')
            ->set('sellingPrice', '100.00')
            ->assertSet('sellingPriceGross', '118.00');
    }

    public function test_typing_the_gross_selling_price_fills_the_net_and_keeps_what_was_typed(): void
    {
        $this->form()
            ->set('vatRate', '18.00')
            ->set('sellingPriceGross', '120.00')
            ->assertSet('sellingPrice', '101.69')
            ->assertSet('sellingPriceGross', '120.00');
    }

    public function test_a_gross_price_survives_saving_and_reopening_exactly(): void
    {
        $this->form()
            ->set('code', 'G-1')
            ->set('name', 'Gross item')
            ->set('vatRate', '18.00')
            ->set('sellingPriceGross', '120.00')
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::where('code', 'G-1')->firstOrFail();
        $this->assertSame('101.69', $item->selling_price);
        $this->assertSame('120.00', $item->selling_price_gross);

        $this->form($item)->assertSet('sellingPriceGross', '120.00')->assertSet('sellingPrice', '101.69');
    }

    public function test_a_net_price_is_stored_without_a_gross(): void
    {
        $this->form()
            ->set('code', 'N-1')
            ->set('name', 'Net item')
            ->set('vatRate', '18.00')
            ->set('sellingPrice', '100.00')
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::where('code', 'N-1')->firstOrFail();
        $this->assertSame('100.00', $item->selling_price);
        $this->assertNull($item->selling_price_gross);

        $this->form($item)->assertSet('sellingPriceGross', '118.00');
    }

    public function test_changing_the_vat_rate_recomputes_the_field_that_was_not_typed(): void
    {
        $this->form()
            ->set('sellingPriceGross', '120.00')
            ->set('vatRate', '5.00')
            ->assertSet('sellingPriceGross', '120.00')
            ->assertSet('sellingPrice', '114.29');
    }

    public function test_the_cost_price_pair_uses_the_purchase_rate_or_falls_back_to_the_sales_rate(): void
    {
        $this->form()
            ->set('vatRate', '18.00')
            ->set('costPrice', '100.00')
            ->assertSet('costPriceGross', '118.00')
            ->set('purchaseVatRate', '5.00')
            ->assertSet('costPriceGross', '105.00')
            ->set('costPriceGross', '210.00')
            ->assertSet('costPrice', '200.00');
    }

    public function test_clearing_one_field_clears_the_other(): void
    {
        $this->form()
            ->set('sellingPrice', '100.00')
            ->set('sellingPrice', '')
            ->assertSet('sellingPriceGross', '');
    }

    public function test_the_sales_invoice_uses_the_gross_price_of_the_item(): void
    {
        $item = Item::factory()->for($this->company)->create([
            'vat_rate' => 18, 'selling_price' => '101.69', 'selling_price_gross' => '120.00',
        ]);

        Livewire::test(SalesInvoiceForm::class, ['company' => $this->company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.price_basis', 'gross')
            ->assertSet('lines.0.unit_price_gross', '120.00')
            ->assertSet('lines.0.unit_price', '101.69');
    }

    public function test_the_purchase_invoice_uses_the_gross_cost_of_the_item(): void
    {
        $item = Item::factory()->for($this->company)->create([
            'vat_rate' => 18, 'purchase_vat_rate' => 5, 'cost_price' => '95.24', 'cost_price_gross' => '100.00',
        ]);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $this->company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.price_basis', 'gross')
            ->assertSet('lines.0.unit_price_gross', '100.00')
            ->assertSet('lines.0.unit_price', '95.24');
    }

    public function test_an_item_without_a_stored_gross_still_fills_the_invoice_by_net(): void
    {
        $item = Item::factory()->for($this->company)->create(['vat_rate' => 18, 'selling_price' => '100.00']);

        Livewire::test(SalesInvoiceForm::class, ['company' => $this->company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.price_basis', 'net')
            ->assertSet('lines.0.unit_price', '100.00');
    }
}
