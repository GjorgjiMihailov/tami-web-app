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

    public function test_partner_defaults_to_legal_entity_type(): void
    {
        $partner = Partner::factory()->create();

        $this->assertSame('legal_entity', $partner->type);
        $this->assertFalse($partner->is_vat_registered);
    }

    public function test_partner_stores_legal_entity_fields(): void
    {
        $partner = Partner::factory()->create([
            'type' => 'legal_entity',
            'registration_number' => '7080123',
            'director_name' => 'Марко Марковски',
            'is_vat_registered' => true,
            'vat_number' => 'MK4030012345678',
        ]);

        $this->assertSame('7080123', $partner->registration_number);
        $this->assertSame('Марко Марковски', $partner->director_name);
        $this->assertTrue($partner->is_vat_registered);
        $this->assertSame('MK4030012345678', $partner->vat_number);
    }

    public function test_individual_factory_state_omits_legal_entity_fields(): void
    {
        $partner = Partner::factory()->individual()->create();

        $this->assertSame('individual', $partner->type);
        $this->assertNull($partner->registration_number);
        $this->assertNull($partner->director_name);
        $this->assertFalse($partner->is_vat_registered);
        $this->assertNull($partner->vat_number);
    }
}
