<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($item->company->is($company));
    }

    public function test_item_can_have_a_preferred_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['preferred_partner_id' => $partner->id]);

        $this->assertTrue($item->preferredPartner->is($partner));
    }

    public function test_code_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Item::factory()->for($company)->create(['code' => 'SKU-1']);
    }

    public function test_new_items_default_to_product_type_with_no_price_or_barcode(): void
    {
        $item = Item::factory()->create();

        $this->assertSame('product', $item->type);
        $this->assertFalse($item->is_made_in_mk);
        $this->assertNull($item->selling_price);
        $this->assertNull($item->barcode);
        $this->assertFalse($item->isService());
    }

    public function test_service_factory_state_sets_type_to_service(): void
    {
        $item = Item::factory()->service()->create();

        $this->assertSame('service', $item->type);
        $this->assertTrue($item->isService());
    }

    public function test_item_stores_selling_price_and_made_in_mk_flag(): void
    {
        $item = Item::factory()->create(['selling_price' => '199.99', 'is_made_in_mk' => true]);

        $this->assertSame('199.99', (string) $item->selling_price);
        $this->assertTrue($item->is_made_in_mk);
    }

    public function test_barcode_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['barcode' => '3800000000017']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Item::factory()->for($company)->create(['barcode' => '3800000000017']);
    }

    public function test_barcode_can_repeat_across_different_companies(): void
    {
        Item::factory()->create(['barcode' => '3800000000017']);
        $item = Item::factory()->create(['barcode' => '3800000000017']);

        $this->assertSame('3800000000017', $item->barcode);
    }
}
