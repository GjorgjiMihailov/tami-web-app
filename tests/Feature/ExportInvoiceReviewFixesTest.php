<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Services\CompanyDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Поправки по завршната ревизија на дел 4. */
class ExportInvoiceReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function legal(): array
    {
        Role::findOrCreate('admin');
        $company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return [$company, $partner, $admin];
    }

    private function form(Company $company, User $admin)
    {
        return Livewire::actingAs($admin)->test(SalesInvoiceForm::class, ['company' => $company]);
    }

    private function fullLine(string $rate, string $treatment): array
    {
        return [
            'item_id' => '', 'description' => 'Ставка', 'quantity' => '1', 'unit_price' => '100',
            'unit_price_gross' => '118', 'price_basis' => 'net', 'vat_rate' => $rate,
            'discount_percent' => '0', 'vat_treatment' => $treatment,
        ];
    }

    public function test_a_line_added_to_an_export_invoice_is_already_without_vat(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->call('addLine')
            ->assertSet('lines.1.vat_treatment', 'export')
            ->assertSet('lines.1.vat_rate', '0.00');
    }

    public function test_picking_an_item_on_an_export_invoice_keeps_the_line_without_vat(): void
    {
        [$company, , $admin] = $this->legal();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00', 'selling_price' => '100.00']);

        $this->form($company, $admin)
            ->set('isExport', true)
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.vat_treatment', 'export')
            ->assertSet('lines.0.vat_rate', '0.00');
    }

    public function test_unticking_restores_each_lines_own_rate_and_treatment(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('lines', [$this->fullLine('5.00', 'standard'), $this->fullLine('0.00', 'exempt_with_credit')])
            ->set('isExport', true)
            ->assertSet('lines.0.vat_rate', '0.00')
            ->set('isExport', false)
            ->assertSet('lines.0.vat_rate', '5.00')
            ->assertSet('lines.0.vat_treatment', 'standard')
            ->assertSet('lines.1.vat_treatment', 'exempt_with_credit');
    }

    public function test_a_line_added_during_export_gets_the_company_default_when_unticked(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->call('addLine')
            ->set('isExport', false)
            ->assertSet('lines.1.vat_treatment', 'standard')
            ->assertSet('lines.1.vat_rate', '18.00');
    }

    public function test_the_dashboard_totals_count_a_foreign_invoice_in_denars(): void
    {
        [$company, $partner] = $this->legal();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_date' => '2026-10-28',
            'due_date' => '2026-11-10', 'currency' => 'EUR', 'exchange_rate' => '61.500000', 'is_export' => true,
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        SalesInvoicePayment::create([
            'sales_invoice_id' => $invoice->id, 'amount' => '400.00', 'payment_date' => '2026-11-01',
            'payment_method' => 'bank', 'created_by' => User::factory()->create()->id, 'exchange_rate' => '61.500000',
        ]);

        // 1.000 EUR × 61,50; ненаплатено 600 EUR × 61,50.
        $this->assertSame('61500.00', CompanyDashboardQuery::revenue($company, 2026));
        $this->assertSame('36900.00', CompanyDashboardQuery::receivable($company, 2026));
    }

    public function test_the_pdf_of_another_companys_export_invoice_is_404_even_with_a_language(): void
    {
        [$company, $partner, $admin] = $this->legal();
        $other = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'currency' => 'EUR', 'exchange_rate' => '61.5', 'is_export' => true,
        ]);

        $this->actingAs($admin)->get(route('sales-invoices.pdf', [$other, $invoice]).'?lang=en')->assertNotFound();
    }
}
