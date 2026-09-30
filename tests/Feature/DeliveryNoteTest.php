<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    // ---- Модел ----

    public function test_a_delivery_note_belongs_to_its_company_and_its_deliverable(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);

        $note = DeliveryNote::factory()->create([
            'company_id' => $company->id,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => $proforma->id,
            'delivery_note_number_formatted' => 'ИСП-2026/1',
        ]);

        $note->refresh();
        $this->assertTrue($note->company->is($company));
        $this->assertTrue($note->deliverable->is($proforma));
        $this->assertSame('ИСП-2026/1', $note->delivery_note_number_formatted);
    }

    public function test_the_company_defaults_its_delivery_note_prefix(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('ИСП-', $company->delivery_note_number_prefix);
    }
}
