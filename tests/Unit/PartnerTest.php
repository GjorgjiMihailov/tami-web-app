<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        $this->assertTrue($partner->company->is($company));
    }

    public function test_partner_stores_full_contact_details(): void
    {
        $partner = Partner::factory()->create([
            'name' => 'АКАУНТ СОЛУШН ДООЕЛ',
            'tax_id' => '4030012345678',
            'email' => 'contact@akaunt.mk',
            'phone' => '+389 70 123 456',
            'address' => 'ул. Партизанска бр. 1, Скопје',
        ]);

        $this->assertSame('4030012345678', $partner->tax_id);
        $this->assertSame('contact@akaunt.mk', $partner->email);
    }
}
