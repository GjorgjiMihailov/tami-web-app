<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockMovementForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMovementFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_records_a_receipt(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '10')
            ->set('unitCost', '50.00')
            ->set('movementDate', '2026-01-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => 'receipt']);
        $this->assertDatabaseHas('stock_levels', ['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity_on_hand' => 10]);
    }

    public function test_it_records_a_transfer_between_two_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouseA, '10', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'transfer'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouseA->id)
            ->set('toWarehouseId', (string) $warehouseB->id)
            ->set('quantity', '4')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_levels', ['item_id' => $item->id, 'warehouse_id' => $warehouseB->id, 'quantity_on_hand' => 4]);
    }

    public function test_transfer_requires_a_different_destination_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'transfer'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('toWarehouseId', (string) $warehouse->id)
            ->set('quantity', '1')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['toWarehouseId']);
    }

    public function test_it_records_a_negative_adjustment_with_a_reason(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '10', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'adjustment'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('direction', 'decrease')
            ->set('quantity', '2')
            ->set('reason', 'Damaged goods')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'type' => 'adjustment', 'quantity' => -2, 'reason' => 'Damaged goods']);
    }

    public function test_issuing_more_than_on_hand_shows_an_error_instead_of_a_500(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '5', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'issue'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '6')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['quantity']);
    }

    public function test_client_can_record_a_movement_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '10')
            ->set('unitCost', '50.00')
            ->set('movementDate', '2026-01-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'type' => 'receipt']);
    }

    public function test_an_invalid_movement_type_in_the_url_is_a_404(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $this->get(route('inventory.stock-movements.create', [$company, 'bogus-type']))->assertNotFound();
    }

    public function test_lookup_by_code_sets_the_item_when_found(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['code' => 'ABC-123']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'ABC-123')
            ->assertSet('itemId', (string) $item->id)
            ->assertHasNoErrors();
    }

    public function test_lookup_by_code_adds_an_error_when_not_found(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'NOT-A-REAL-CODE')
            ->assertSet('itemId', '')
            ->assertHasErrors(['scannedCode']);
    }

    public function test_transferring_more_than_on_hand_shows_an_error_instead_of_a_500(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouseA, '5', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'transfer'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouseA->id)
            ->set('toWarehouseId', (string) $warehouseB->id)
            ->set('quantity', '6')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['quantity']);
    }

    public function test_a_decrease_adjustment_larger_than_on_hand_shows_an_error_instead_of_a_500(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '5', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'adjustment'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('direction', 'decrease')
            ->set('quantity', '6')
            ->set('reason', 'Physical count correction')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['quantity']);
    }

    public function test_lookup_by_code_does_not_find_an_item_belonging_to_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        Item::factory()->for($otherCompany)->create(['code' => 'OTHER-CODE']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'OTHER-CODE')
            ->assertSet('itemId', '')
            ->assertHasErrors(['scannedCode']);
    }

    public function test_scanning_a_known_code_selects_the_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['code' => 'SKU-999']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'SKU-999')
            ->assertSet('itemId', (string) $item->id)
            ->assertHasNoErrors();
    }

    public function test_scanning_an_unknown_code_shows_an_error(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'DOES-NOT-EXIST')
            ->assertHasErrors(['scannedCode'])
            ->assertSet('itemId', '');
    }
}
