<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockOnHandReport;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockOnHandReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_totals_across_warehouses_by_default(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->assertSee('Widget')
            ->assertSee('500,00');
    }

    public function test_it_shows_cost_and_selling_value_for_a_specific_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->set('warehouseId', $warehouse->id)
            ->assertSee('Набавна вредност')
            ->assertSee('Продажна вредност')
            ->assertSee('500,00')
            ->assertSee('800,00');
    }

    public function test_it_shows_selling_value_in_the_totals_across_warehouses_view(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->assertSee('Вкупна набавна вредност')
            ->assertSee('Вкупна продажна вредност')
            ->assertSee('800,00');
    }

    public function test_it_links_to_the_pdf_download(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('inventory.reports.stock-on-hand', $company))
            ->assertOk()
            ->assertSee(route('inventory.reports.stock-on-hand.pdf', $company), false);
    }

    public function test_it_links_to_the_pdf_download_with_the_selected_warehouse(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->set('warehouseId', $warehouse->id)
            ->assertSee(route('inventory.reports.stock-on-hand.pdf', $company).'?warehouseId='.$warehouse->id, false);
    }

    public function test_the_stock_on_hand_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand', $company))
            ->assertOk();
    }
}
