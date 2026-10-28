<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\InvoiceLanguage;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function invoice(bool $export = true, string $status = 'confirmed'): SalesInvoice
    {
        $company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Ltd']);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => $status, 'fiscal_year' => 2026, 'invoice_number' => 7,
            'invoice_number_formatted' => '2026/7', 'invoice_date' => '2026-10-28', 'due_date' => '2026-11-10',
            'is_export' => $export, 'currency' => $export ? 'EUR' : 'MKD', 'exchange_rate' => $export ? '61.50' : '1',
        ]);
        $invoice->lines()->create(['description' => 'Consulting services', 'quantity' => '2', 'unit_price' => '750.00', 'vat_rate' => '0.00', 'vat_treatment' => $export ? 'export' : 'standard']);

        return $invoice;
    }

    private function pdf(SalesInvoice $invoice, string $query = '')
    {
        return $this->actingAs($this->admin)->get(route('sales-invoices.pdf', [$invoice->company, $invoice]).$query);
    }

    public function test_an_export_invoice_pdf_is_available_in_both_languages(): void
    {
        $invoice = $this->invoice();

        foreach (['mk', 'en'] as $lang) {
            $response = $this->pdf($invoice, "?lang={$lang}");

            $response->assertOk();
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString("-{$lang}.pdf", (string) $response->headers->get('Content-Disposition'));
        }
    }

    public function test_the_chosen_language_reaches_the_pdf_view_without_being_saved(): void
    {
        $invoice = $this->invoice();
        $seen = null;
        $fake = Mockery::mock(PdfDocument::class);
        $fake->shouldReceive('download')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        Pdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$seen) {
            $seen = $data['invoice']->language;

            return $view === 'pdf.sales-invoice';
        })->andReturn($fake);

        $this->pdf($invoice, '?lang=en')->assertOk();

        $this->assertSame(InvoiceLanguage::EN, $seen);
        $this->assertSame(InvoiceLanguage::MK, $invoice->fresh()->language);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->pdf($this->invoice(), '?lang=xx')->assertNotFound();
    }

    public function test_the_language_parameter_is_ignored_on_a_normal_invoice(): void
    {
        $response = $this->pdf($this->invoice(false), '?lang=en');

        $response->assertOk();
        $this->assertStringNotContainsString('-en.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_without_a_language_the_pdf_works_as_before(): void
    {
        $response = $this->pdf($this->invoice());

        $response->assertOk();
        $this->assertStringContainsString('invoice-2026-7.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_unconfirmed_export_invoice_is_still_forbidden(): void
    {
        $this->pdf($this->invoice(true, 'draft'), '?lang=en')->assertForbidden();
    }

    public function test_the_show_page_offers_two_pdf_buttons_only_for_an_export_invoice(): void
    {
        $export = $this->invoice();
        $normal = $this->invoice(false);

        Livewire::actingAs($this->admin)->test(SalesInvoiceShow::class, ['company' => $export->company, 'salesInvoice' => $export])
            ->assertSee('PDF — македонски')
            ->assertSee('PDF — English');

        Livewire::actingAs($this->admin)->test(SalesInvoiceShow::class, ['company' => $normal->company, 'salesInvoice' => $normal])
            ->assertSee('Преземи PDF')
            ->assertDontSee('PDF — English');
    }
}
