<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemIndex;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemPermanentDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->company = Company::factory()->create();
    }

    private function index()
    {
        return Livewire::test(ItemIndex::class, ['company' => $this->company]);
    }

    public function test_an_inactive_unused_item_can_be_deleted_permanently(): void
    {
        $item = Item::factory()->for($this->company)->create(['is_active' => false]);

        $this->index()->call('deleteItem', $item->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_an_active_item_cannot_be_deleted(): void
    {
        $item = Item::factory()->for($this->company)->create(['is_active' => true]);

        $this->index()->call('deleteItem', $item->id)->assertHasErrors('delete');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_an_item_used_on_an_invoice_line_is_kept(): void
    {
        $item = Item::factory()->for($this->company)->create(['is_active' => false]);
        $invoice = SalesInvoice::factory()->for($this->company)->create();
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->index()->call('deleteItem', $item->id)->assertHasErrors('delete');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_an_item_used_on_a_purchase_line_is_kept(): void
    {
        $item = Item::factory()->for($this->company)->create(['is_active' => false]);
        $invoice = PurchaseInvoice::factory()->for($this->company)->create();
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->index()->call('deleteItem', $item->id)->assertHasErrors('delete');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_another_companys_item_is_not_found(): void
    {
        $other = Item::factory()->for(Company::factory()->create())->create(['is_active' => false]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->index()->call('deleteItem', $other->id);
    }

    public function test_the_bulk_button_deletes_only_unused_inactive_items(): void
    {
        $free = Item::factory()->for($this->company)->create(['is_active' => false]);
        $active = Item::factory()->for($this->company)->create(['is_active' => true]);
        $used = Item::factory()->for($this->company)->create(['is_active' => false]);
        $invoice = SalesInvoice::factory()->for($this->company)->create();
        $invoice->lines()->create(['item_id' => $used->id, 'description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->index()->call('deleteUnusedInactive')->assertSee('Избришани: 1');

        $this->assertDatabaseMissing('items', ['id' => $free->id]);
        $this->assertDatabaseHas('items', ['id' => $active->id]);
        $this->assertDatabaseHas('items', ['id' => $used->id]);
    }

    public function test_the_delete_buttons_are_offered_for_inactive_items(): void
    {
        $item = Item::factory()->for($this->company)->create(['is_active' => false]);

        $this->index()->set('filter', 'inactive')->assertSee('Избриши ги неискористените')
            ->call('select', $item->id)->assertSee('Избриши трајно');
    }
}
