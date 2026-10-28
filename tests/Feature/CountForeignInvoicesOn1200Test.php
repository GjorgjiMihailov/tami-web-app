<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CountForeignInvoicesOn1200Test extends TestCase
{
    use RefreshDatabase;

    private function confirmedEuroInvoice(Company $company): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '500.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    public function test_it_reports_the_number_of_foreign_invoices_posted_to_1200(): void
    {
        // Фирма со стара шема: само 1200, без девизните редови.
        $old = Company::factory()->create(['is_vat_registered' => false]);
        $scheme = PostingSchemes::for($old, PostingDocType::SALES_INVOICE);
        $scheme->rows()->whereIn('formula', ['ВКУПНО_ДОБРА', 'ВКУПНО_УСЛУГИ'])->delete();
        $scheme->rows()->where('formula', 'ВКУПНО')->update(['condition' => null]);
        $this->confirmedEuroInvoice($old);

        $this->confirmedEuroInvoice(Company::factory()->create(['is_vat_registered' => false]));

        Artisan::call('invoices:count-foreign-on-1200');

        $this->assertStringContainsString('Девизни фактури книжени на 1200: 1', Artisan::output());
    }
}
