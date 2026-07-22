<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_morphs_to_a_purchase_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $this->assertInstanceOf(PurchaseInvoice::class, $document->documentable);
        $this->assertSame($invoice->id, $document->documentable->id);
        $this->assertSame('purchase_invoice', $document->documentable_type);
        $this->assertTrue($invoice->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_sales_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();

        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('sales_invoice', $document->documentable_type);
        $this->assertTrue($invoice->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();

        $document = Document::factory()->for($entry, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('journal_entry', $document->documentable_type);
        $this->assertTrue($entry->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        $document = Document::factory()->for($partner, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('partner', $document->documentable_type);
        $this->assertTrue($partner->documents->contains($document));
    }

    public function test_a_soft_deleted_document_is_excluded_from_the_documentable_relation(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $document->delete();

        $this->assertCount(0, $invoice->documents()->get());
        $this->assertNotNull($document->fresh()->deleted_at);
    }
}
