<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemIndex;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_it_lists_the_companys_items(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Widget A');
    }

    public function test_search_filters_by_name_or_code(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A', 'code' => 'SKU-1']);
        Item::factory()->for($company)->create(['name' => 'Gadget B', 'code' => 'SKU-2']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('search', 'Widget')
            ->assertSee('Widget A')
            ->assertDontSee('Gadget B');
    }

    public function test_the_items_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $this->admin();

        $this->get(route('inventory.items.index', $company))->assertOk();
        $this->get(route('inventory.items.index', [$company, 'item' => $item->id]))->assertOk();
    }

    public function test_the_default_filter_shows_only_active_items(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Активен-А']);
        Item::factory()->for($company)->create(['name' => 'Неактивен-Б', 'is_active' => false]);
        Item::factory()->for($company)->service()->create(['name' => 'Услуга-В']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Активен-А')
            ->assertDontSee('Неактивен-Б')
            ->set('filter', 'inactive')
            ->assertSee('Неактивен-Б')
            ->assertDontSee('Активен-А')
            ->set('filter', 'all')
            ->assertSee('Неактивен-Б')
            ->assertSee('Активен-А')
            ->set('filter', 'service')
            ->assertSee('Услуга-В')
            ->assertDontSee('Активен-А');
    }

    public function test_an_unknown_filter_falls_back_to_active(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Активен-А']);
        Item::factory()->for($company)->create(['name' => 'Неактивен-Б', 'is_active' => false]);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('filter', 'nonsense')
            ->assertSee('Активен-А')
            ->assertDontSee('Неактивен-Б');
    }

    public function test_the_list_links_to_the_new_item_page(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSeeHtml(route('inventory.items.create', $company));
    }

    public function test_nothing_is_selected_until_an_item_is_picked(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A', 'description' => 'Скриен-опис']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Избери артикл од листата')
            ->assertDontSee('Скриен-опис');
    }

    public function test_selecting_an_item_shows_its_overview_with_edit_link(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create([
            'name' => 'Widget A', 'description' => 'Опис-на-артикл', 'category' => 'Козметика',
            'selling_price' => '250.00', 'cost_price' => '120.00', 'vat_rate' => 18, 'purchase_vat_rate' => 5,
        ]);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('select', $item->id)
            ->assertSee('Опис-на-артикл')
            ->assertSee('Козметика')
            ->assertSee('250,00')
            ->assertSee('120,00')
            ->assertSee('Основни податоци')
            ->assertSeeHtml(route('inventory.items.edit', [$company, $item]));
    }

    public function test_an_item_of_another_company_cannot_be_selected(): void
    {
        $company = Company::factory()->create();
        $foreign = Item::factory()->for(Company::factory()->create())->create(['name' => 'Туѓ-артикл']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company, 'selectedId' => $foreign->id])
            ->assertDontSee('Туѓ-артикл');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(ItemIndex::class, ['company' => $company])->call('select', $foreign->id);
    }

    public function test_the_overview_shows_stock_per_warehouse_for_a_product_but_not_a_service(): void
    {
        $company = Company::factory()->create();
        $product = Item::factory()->for($company)->create(['unit_of_measure' => 'кг']);
        $service = Item::factory()->for($company)->service()->create();
        $warehouse = Warehouse::factory()->for($company)->create(['name' => 'Главен магацин']);
        StockLevel::factory()->create(['item_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity_on_hand' => '12.500']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('select', $product->id)
            ->assertSee('Главен магацин')
            ->assertSee('12,5 кг')
            ->call('select', $service->id)
            ->assertDontSee('Главен магацин')
            ->assertDontSee('Залиха');
    }

    public function test_the_sales_summary_counts_only_confirmed_invoices_in_the_period(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $confirmed = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => now()->toDateString()]);
        $draft = SalesInvoice::factory()->for($company)->create(['status' => 'draft', 'invoice_date' => now()->toDateString()]);
        $old = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => now()->subYears(2)->toDateString()]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $confirmed->id, 'item_id' => $item->id, 'quantity' => '3.000', 'unit_price' => '100.00']);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $draft->id, 'item_id' => $item->id, 'quantity' => '9.000', 'unit_price' => '100.00']);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $old->id, 'item_id' => $item->id, 'quantity' => '7.000', 'unit_price' => '100.00']);
        $this->admin();

        $summary = \App\Services\Inventory\ItemInsights::salesSummary($item, 'this_month');

        $this->assertSame(300.0, $summary['total']);
        $this->assertSame(3.0, $summary['quantity']);
        $this->assertCount(1, $summary['days']);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('select', $item->id)
            ->assertSee('300,00');
    }

    public function test_the_transactions_tab_lists_invoices_and_manual_stock_movements_without_duplicates(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create(['name' => 'Магацин-1']);

        $sale = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_number_formatted' => 'ФК-2026/7']);
        $viaInvoice = StockMovement::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => 'issue']);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $sale->id, 'item_id' => $item->id, 'stock_movement_id' => $viaInvoice->id]);

        $purchase = PurchaseInvoice::factory()->for($company)->create(['supplier_invoice_number' => 'ДОБ-991']);
        PurchaseInvoiceLine::factory()->create(['purchase_invoice_id' => $purchase->id, 'item_id' => $item->id]);

        StockMovement::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => 'adjustment']);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('select', $item->id)
            ->set('tab', 'transactions')
            ->assertSee('ФК-2026/7')
            ->assertSee('ДОБ-991')
            ->assertSee('Корекција')
            ->assertDontSee('Издавање') // движењето од фактурата не се повторува
            ->set('transactionFilter', 'purchases')
            ->assertSee('ДОБ-991')
            ->assertDontSee('ФК-2026/7');
    }

    public function test_the_transactions_tab_only_shows_this_items_rows(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $other = Item::factory()->for($company)->create();
        $purchase = PurchaseInvoice::factory()->for($company)->create(['supplier_invoice_number' => 'ТУЃА-1']);
        PurchaseInvoiceLine::factory()->create(['purchase_invoice_id' => $purchase->id, 'item_id' => $other->id]);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('select', $item->id)
            ->set('tab', 'transactions')
            ->assertDontSee('ТУЃА-1')
            ->assertSee('Нема трансакции.');
    }

    public function test_toggling_active_flips_the_flag(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['is_active' => true]);
        $this->admin();

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('toggleActive', $item->id);

        $this->assertFalse($item->fresh()->is_active);
    }
}
