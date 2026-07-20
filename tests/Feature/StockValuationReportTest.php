<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockValuationReport;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockValuationReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_the_company_wide_total_by_default(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', now()->toDateString(), $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockValuationReport::class, ['company' => $company])
            ->assertSee('Total')
            ->assertSee('500.00');
    }

    public function test_the_valuation_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-valuation', $company))
            ->assertOk();
    }
}
