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
}
