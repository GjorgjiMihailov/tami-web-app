<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemIndex;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_items(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Widget A');
    }

    public function test_client_can_add_an_item_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-100')
            ->set('newName', 'Widget B')
            ->set('newUnitOfMeasure', 'kg')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-100', 'unit_of_measure' => 'kg']);
    }

    public function test_search_filters_by_name_or_code(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A', 'code' => 'SKU-1']);
        Item::factory()->for($company)->create(['name' => 'Gadget B', 'code' => 'SKU-2']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('search', 'Widget')
            ->assertSee('Widget A')
            ->assertDontSee('Gadget B');
    }

    public function test_the_items_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.items.index', $company))
            ->assertOk();
    }
}
