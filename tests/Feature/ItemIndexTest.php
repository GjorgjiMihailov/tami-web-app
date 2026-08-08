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

    public function test_add_item_form_accepts_the_new_fields(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-200')
            ->set('newName', 'Service Item')
            ->set('newUnitOfMeasure', 'hour')
            ->set('newSellingPrice', '150.00')
            ->set('newType', 'service')
            ->set('newIsMadeInMk', true)
            ->set('newBarcode', '3800000000024')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', [
            'company_id' => $company->id,
            'code' => 'SKU-200',
            'selling_price' => '150.00',
            'type' => 'service',
            'is_made_in_mk' => true,
            'barcode' => '3800000000024',
        ]);
    }

    public function test_a_duplicate_barcode_is_rejected_on_add(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['barcode' => '3800000000017']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-201')
            ->set('newName', 'Widget')
            ->set('newUnitOfMeasure', 'piece')
            ->set('newBarcode', '3800000000017')
            ->call('addItem')
            ->assertHasErrors(['newBarcode']);
    }

    public function test_editing_an_item_updates_all_fields(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Old Name', 'type' => 'product']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('startEditingItem', $item->id)
            ->assertSet('editName', 'Old Name')
            ->set('editName', 'New Name')
            ->set('editSellingPrice', '75.50')
            ->set('editType', 'service')
            ->set('editIsMadeInMk', true)
            ->set('editBarcode', '3800000000031')
            ->call('updateItem', $item->id)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame('New Name', $item->name);
        $this->assertSame('75.50', (string) $item->selling_price);
        $this->assertSame('service', $item->type);
        $this->assertTrue($item->is_made_in_mk);
        $this->assertSame('3800000000031', $item->barcode);
    }

    public function test_a_client_can_edit_their_own_companys_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('startEditingItem', $item->id)
            ->set('editName', 'Renamed by client')
            ->call('updateItem', $item->id)
            ->assertHasNoErrors();

        $this->assertSame('Renamed by client', $item->fresh()->name);
    }

    public function test_the_list_shows_type_and_made_in_mk_columns(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Service X', 'type' => 'service', 'is_made_in_mk' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Услуга')
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
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
}
