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

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
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

    public function test_the_list_links_to_the_new_item_page_and_to_each_items_edit_page(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Нов артикл')
            ->assertSeeHtml(route('inventory.items.create', $company))
            ->assertSeeHtml(route('inventory.items.edit', [$company, $item]));
    }

    public function test_the_list_shows_type_cost_price_and_made_in_mk_columns(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Service X', 'type' => 'service', 'is_made_in_mk' => true, 'cost_price' => '12.50']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Услуга')
            ->assertSee('Набавна цена')
            ->assertSee('Да');
    }

    public function test_the_item_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSeeHtml('shadow-card p-0')
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }

    public function test_toggling_active_flips_the_flag(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['is_active' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('toggleActive', $item->id);

        $this->assertFalse($item->fresh()->is_active);
    }
}
