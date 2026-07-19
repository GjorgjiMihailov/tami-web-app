<?php

namespace Tests\Feature;

use App\Livewire\Inventory\WarehouseIndex;
use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_warehouses(): void
    {
        $company = Company::factory()->create();
        Warehouse::factory()->for($company)->create(['name' => 'Main Store']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->assertSee('Main Store');
    }

    public function test_client_can_add_a_warehouse_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->set('newName', 'Second Location')
            ->call('addWarehouse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('warehouses', ['company_id' => $company->id, 'name' => 'Second Location']);
    }

    public function test_duplicate_warehouse_name_in_the_same_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        Warehouse::factory()->for($company)->create(['name' => 'Main Store']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->set('newName', 'Main Store')
            ->call('addWarehouse')
            ->assertHasErrors(['newName' => 'unique']);
    }

    public function test_the_warehouses_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.warehouses.index', $company))
            ->assertOk();
    }
}
