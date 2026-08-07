<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceIncomingEfakturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_one_incoming_efaktura_document(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['purchase_invoice_id' => $invoice->id]);

        $this->assertTrue($invoice->fresh()->incomingEfakturaDocument->is($document));
    }

    public function test_incoming_efaktura_document_is_null_for_a_manually_entered_invoice(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertNull($invoice->fresh()->incomingEfakturaDocument);
    }
}
