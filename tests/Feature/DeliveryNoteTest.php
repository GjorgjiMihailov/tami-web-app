<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Services\Invoicing\DeliveryNoteService;
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

    // ---- Нумерирање ----

    public function test_it_numbers_with_its_own_prefix_and_series_across_both_source_types(): void
    {
        $company = Company::factory()->create(['delivery_note_number_prefix' => 'ISP/']);
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 1]);

        $service = app(DeliveryNoteService::class);
        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($invoice);

        $this->assertSame('ISP/'.now()->year.'/1', $first->delivery_note_number_formatted);
        $this->assertSame('ISP/'.now()->year.'/2', $second->delivery_note_number_formatted);
    }

    public function test_each_company_has_its_own_series(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $proformaA = ProformaInvoice::factory()->create(['company_id' => $a->id, 'partner_id' => Partner::factory()->for($a)->create()->id, 'status' => 'confirmed']);
        $proformaB = ProformaInvoice::factory()->create(['company_id' => $b->id, 'partner_id' => Partner::factory()->for($b)->create()->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $service->createOrGetFor($proformaA);
        $first = $service->createOrGetFor($proformaB);

        $this->assertSame(1, $first->delivery_note_number);
    }

    public function test_calling_it_twice_for_the_same_source_returns_the_same_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($proforma);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_draft_proforma_or_invoice_cannot_get_a_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);

        $this->expectException(InvalidInvoiceStateException::class);
        app(DeliveryNoteService::class)->createOrGetFor($draft);
    }

    // ---- PDF-шаблон ----

    public function test_the_pdf_view_shows_quantities_without_any_price_or_vat(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач Ана']);
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id, 'description' => 'Канцелариски стол', 'quantity' => '3.000', 'unit_price' => '999.00']);
        $note = DeliveryNote::factory()->create([
            'company_id' => $company->id,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => $proforma->id,
            'delivery_note_number_formatted' => 'ИСП-2026/1',
        ]);

        $html = view('pdf.delivery-note', [
            'deliveryNote' => $note,
            'company' => $company,
            'partner' => $partner,
            'lines' => $proforma->load('lines.item')->lines,
            'sourceLabel' => 'профактура',
            'sourceNumber' => $proforma->proforma_number_formatted,
        ])->render();

        $this->assertStringContainsString('Испратница', $html);
        $this->assertStringContainsString('ИСП-2026/1', $html);
        $this->assertStringContainsString('Купувач Ана', $html);
        $this->assertStringContainsString('Канцелариски стол', $html);
        $this->assertStringContainsString('3.000', $html);
        $this->assertStringContainsString('ПРЕДАЛ', $html);
        $this->assertStringContainsString('ПРИМИЛ', $html);
        $this->assertStringNotContainsString('999.00', $html);
        $this->assertStringNotContainsString('ДДВ', $html);
    }

    // ---- Рути и PDF-преземање ----

    public function test_the_proforma_delivery_note_route_downloads_a_pdf_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id]);
        $this->admin();

        $first = $this->get(route('proformas.delivery-note', [$company, $proforma]));
        $first->assertOk();
        $this->assertStringStartsWith('%PDF-', $first->getContent());

        $this->get(route('proformas.delivery-note', [$company, $proforma]))->assertOk();

        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_draft_proforma_refuses_a_delivery_note_over_http(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->admin();

        $this->get(route('proformas.delivery-note', [$company, $draft]))->assertForbidden();
    }

    public function test_a_proforma_delivery_note_of_another_company_is_404_under_this_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $otherPartner = Partner::factory()->for($other)->create();
        $foreign = ProformaInvoice::factory()->create(['company_id' => $other->id, 'partner_id' => $otherPartner->id, 'status' => 'confirmed']);
        $this->admin();

        $this->get(route('proformas.delivery-note', [$company, $foreign]))->assertNotFound();
    }

    public function test_the_sales_invoice_delivery_note_route_downloads_a_pdf(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 5]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $invoice->id]);
        $this->admin();

        $response = $this->get(route('sales-invoices.delivery-note', [$company, $invoice]));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_draft_sales_invoice_refuses_a_delivery_note_over_http(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->admin();

        $this->get(route('sales-invoices.delivery-note', [$company, $draft]))->assertForbidden();
    }
}
