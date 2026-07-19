<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->assertTrue($warehouse->company->is($company));
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $warehouse = Warehouse::factory()->create(['is_active' => 1]);

        $this->assertIsBool($warehouse->fresh()->is_active);
        $this->assertTrue($warehouse->fresh()->is_active);
    }
}
