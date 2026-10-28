<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_invoice_is_not_an_export_and_the_flag_can_be_stored(): void
    {
        $this->assertFalse((new SalesInvoice)->is_export);

        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $plain = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $export = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'is_export' => true]);

        $this->assertFalse($plain->fresh()->is_export);
        $this->assertTrue($export->fresh()->is_export);
    }
}
