<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemForm;
use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_create_and_edit_pages_render_over_http(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $this->admin();

        $this->get(route('inventory.items.create', $company))->assertOk()->assertSee('Нов артикл');
        $this->get(route('inventory.items.edit', [$company, $item]))->assertOk()->assertSee('Уреди артикл');
    }

    public function test_a_client_can_create_an_item_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(ItemForm::class, ['company' => $company])
            ->set('code', 'SKU-100')
            ->set('name', 'Widget B')
            ->set('unitOfMeasure', 'кг')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('inventory.items.index', $company));

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-100', 'unit_of_measure' => 'кг', 'is_active' => true]);
    }

    public function test_it_saves_every_section(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company])
            ->set('code', 'SKU-200')
            ->set('name', 'Service Item')
            ->set('type', 'service')
            ->set('description', 'Месечно одржување')
            ->set('category', 'Услуги')
            ->set('isMadeInMk', true)
            ->set('barcode', '3800000000024')
            ->set('sellingPrice', '150.00')
            ->set('vatRate', '18')
            ->set('costPrice', '90.00')
            ->set('purchaseVatRate', '5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', [
            'company_id' => $company->id,
            'code' => 'SKU-200',
            'type' => 'service',
            'description' => 'Месечно одржување',
            'selling_price' => '150.00',
            'vat_rate' => '18.00',
            'cost_price' => '90.00',
            'purchase_vat_rate' => '5.00',
            'is_made_in_mk' => true,
            'barcode' => '3800000000024',
        ]);
    }

    public function test_a_blank_purchase_vat_rate_means_the_same_as_the_sales_rate(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company])
            ->set('code', 'SKU-1')
            ->set('name', 'Same rate')
            ->set('vatRate', '5')
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::where('code', 'SKU-1')->first();
        $this->assertNull($item->purchase_vat_rate);
        $this->assertSame('5.00', $item->purchaseVatRate());
    }

    public function test_a_duplicate_code_or_barcode_is_rejected(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-1', 'barcode' => '3800000000017']);
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company])
            ->set('code', 'SKU-1')
            ->set('name', 'Dup')
            ->set('barcode', '3800000000017')
            ->call('save')
            ->assertHasErrors(['code', 'barcode']);
    }

    public function test_editing_keeps_its_own_code_and_updates_the_fields(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Old Name', 'code' => 'SKU-9', 'type' => 'product']);
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company, 'item' => $item])
            ->assertSet('name', 'Old Name')
            ->set('name', 'New Name')
            ->set('costPrice', '75.50')
            ->set('isPurchasable', false)
            ->call('save')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame('New Name', $item->name);
        $this->assertSame('SKU-9', $item->code);
        $this->assertSame('75.50', (string) $item->cost_price);
        $this->assertFalse($item->is_purchasable);
    }

    public function test_a_client_can_edit_their_own_companys_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(ItemForm::class, ['company' => $company, 'item' => $item])
            ->set('name', 'Renamed by client')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed by client', $item->fresh()->name);
    }

    public function test_an_item_of_another_company_cannot_be_opened_under_this_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $foreign = Item::factory()->for($other)->create();
        $this->admin();

        $this->get(route('inventory.items.edit', [$company, $foreign]))->assertNotFound();
    }

    public function test_a_partner_from_another_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $foreignPartner = \App\Models\Partner::factory()->for(Company::factory()->create())->create();
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company])
            ->set('code', 'SKU-1')
            ->set('name', 'X')
            ->set('preferredPartnerId', (string) $foreignPartner->id)
            ->call('save')
            ->assertHasErrors(['preferredPartnerId']);
    }

    public function test_category_and_unit_suggestions_include_what_the_company_already_uses(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['category' => 'Козметика', 'unit_of_measure' => 'флаша']);
        $this->admin();

        Livewire::test(ItemForm::class, ['company' => $company])
            ->assertSeeHtml('<option value="Козметика">')
            ->assertSeeHtml('<option value="флаша">')
            ->assertSeeHtml('<option value="кг">');
    }

    public function test_the_purchase_form_uses_the_purchase_vat_rate_and_cost_price(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $item = Item::factory()->for($company)->create(['vat_rate' => 18, 'purchase_vat_rate' => 5, 'cost_price' => '40.00']);
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.vat_rate', '5.00')
            ->assertSet('lines.0.unit_price', '40.00');
    }

    public function test_invoice_pickers_only_offer_items_flagged_for_that_direction(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Само-продажба-XYZ', 'is_purchasable' => false]);
        Item::factory()->for($company)->create(['name' => 'Само-набавка-XYZ', 'is_sellable' => false]);
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSee('Само-продажба-XYZ')
            ->assertDontSee('Само-набавка-XYZ');

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSee('Само-набавка-XYZ')
            ->assertDontSee('Само-продажба-XYZ');
    }
}
