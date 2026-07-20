<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_inventory_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create();
        Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('inventory.warehouses.index', $company))->assertOk();
        $this->get(route('inventory.items.index', $company))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'receipt']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'issue']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'transfer']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'adjustment']))->assertOk();
        $this->get(route('inventory.reports.stock-on-hand', $company))->assertOk();
        $this->get(route('inventory.reports.item-movement-card', $company))->assertOk();
        $this->get(route('inventory.reports.stock-valuation', $company))->assertOk();
    }

    public function test_inventory_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('inventory.warehouses.index', $company))->assertRedirect(route('login'));
    }
}
