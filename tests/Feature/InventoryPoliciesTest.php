<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_warehouses_and_items(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($client->can('update', $warehouse));
        $this->assertTrue($client->can('update', $item));
        $this->assertTrue($client->can('create', Warehouse::class));
        $this->assertTrue($client->can('create', Item::class));
        $this->assertTrue($client->can('create', StockMovement::class));
    }

    public function test_client_cannot_manage_another_companys_warehouses_or_items(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $warehouse = Warehouse::factory()->for($otherCompany)->create();
        $item = Item::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $warehouse));
        $this->assertFalse($client->can('view', $item));
        $this->assertFalse($client->can('update', $warehouse));
        $this->assertFalse($client->can('update', $item));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_inventory(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);

        $warehouse = Warehouse::factory()->for($companyTheyDoNotManage)->create();
        $item = Item::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $warehouse));
        $this->assertFalse($accountant->can('view', $item));
    }

    public function test_admin_can_manage_inventory_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $warehouse));
        $this->assertTrue($admin->can('update', $item));
    }

    public function test_stock_movement_view_is_scoped_to_the_owning_companys_users(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $item = Item::factory()->for($ownCompany)->create();
        $warehouse = Warehouse::factory()->for($ownCompany)->create();
        $stockMovement = StockMovement::factory()->for($item)->for($warehouse)->create();

        $sameCompanyClient = User::factory()->create(['company_id' => $ownCompany->id]);
        $sameCompanyClient->assignRole('client');
        $otherCompanyClient = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherCompanyClient->assignRole('client');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($sameCompanyClient->can('view', $stockMovement));
        $this->assertTrue($admin->can('view', $stockMovement));
        $this->assertFalse($otherCompanyClient->can('view', $stockMovement));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_inventory(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);

        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $stockMovement = StockMovement::factory()->for($item)->for($warehouse)->create();

        $this->assertTrue($accountant->can('view', $warehouse));
        $this->assertTrue($accountant->can('update', $warehouse));
        $this->assertTrue($accountant->can('view', $item));
        $this->assertTrue($accountant->can('update', $item));
        $this->assertTrue($accountant->can('create', Warehouse::class));
        $this->assertTrue($accountant->can('create', Item::class));
        $this->assertTrue($accountant->can('view', $stockMovement));
    }
}
