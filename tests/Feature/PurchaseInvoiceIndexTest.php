<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_lists_the_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies DOOEL']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'SUP-100']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme Supplies DOOEL')
            ->assertSee('SUP-100');
    }

    public function test_it_filters_by_status(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft', 'supplier_invoice_number' => 'DRAFT-1']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'supplier_invoice_number' => 'CONF-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('CONF-1')
            ->assertDontSee('DRAFT-1');
    }

    public function test_it_shows_pending_incoming_efaktura_documents(): void
    {
        $company = Company::factory()->create();
        IncomingEfakturaDocument::factory()->for($company)->create(['seller_name' => 'Тест Добавувач ДООЕЛ']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Неодлучени е-Фактури')
            ->assertSee('Тест Добавувач ДООЕЛ');
    }

    public function test_it_hides_the_pending_section_when_there_are_no_pending_documents(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertDontSee('Неодлучени е-Фактури');
    }

    public function test_it_shows_a_pdf_download_link_for_an_invoice_from_an_accepted_efaktura_document_with_a_cached_pdf(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        IncomingEfakturaDocument::factory()->for($company)->create([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'purchase_invoice_id' => $invoice->id,
            'efaktura_pdf_path' => 'efaktura-pdfs/incoming/1/1.pdf',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Преземи ПДФ');
    }
}
