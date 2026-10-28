<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\InvoiceLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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

    private function legal(bool $vat = true): array
    {
        Role::findOrCreate('admin');
        $company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => $vat]);
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return [$company, $partner, $admin];
    }

    private function line(string $rate = '18.00', string $treatment = 'standard'): array
    {
        return [[
            'item_id' => '', 'description' => 'Consulting services', 'quantity' => '1', 'unit_price' => '500',
            'vat_rate' => $rate, 'vat_treatment' => $treatment,
        ]];
    }

    private function form(Company $company, User $admin)
    {
        return Livewire::actingAs($admin)->test(SalesInvoiceForm::class, ['company' => $company]);
    }

    public function test_a_legal_entity_can_save_an_export_invoice_in_euros_without_vat(): void
    {
        [$company, $partner, $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-10-28')
            ->set('dueDate', '2026-11-10')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->line('18.00', 'standard'))
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->with('lines')->firstOrFail();
        $this->assertTrue($invoice->is_export);
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame('export', $invoice->lines->first()->vat_treatment);
        $this->assertSame('0.00', (string) $invoice->lines->first()->vat_rate);
        $this->assertSame('500.00', $invoice->grandTotal());
    }

    public function test_an_export_invoice_in_denars_is_refused(): void
    {
        [$company, $partner, $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-10-28')
            ->set('dueDate', '2026-11-10')
            ->set('currency', 'MKD')
            ->set('lines', $this->line())
            ->call('save')
            ->assertHasErrors('currency');

        $this->assertSame(0, SalesInvoice::where('company_id', $company->id)->count());
    }

    public function test_without_the_tick_a_legal_entity_is_still_forced_to_denars(): void
    {
        [$company, $partner, $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-10-28')
            ->set('dueDate', '2026-11-10')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->line())
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertFalse($invoice->is_export);
        $this->assertSame('MKD', $invoice->currency);
    }

    public function test_the_export_checkbox_is_shown_to_a_legal_entity_and_opens_the_currency(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->assertSee('Извозна фактура')
            ->assertDontSeeHtml('id="currency"')
            ->set('isExport', true)
            ->assertSeeHtml('id="currency"');
    }

    public function test_ticking_the_box_switches_to_euros_and_export_lines(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->assertSet('currency', 'EUR')
            ->assertSet('lines.0.vat_treatment', 'export')
            ->assertSet('lines.0.vat_rate', '0.00');
    }

    public function test_unticking_the_box_returns_to_denars_and_standard_lines(): void
    {
        [$company, , $admin] = $this->legal();

        $this->form($company, $admin)
            ->set('isExport', true)
            ->set('isExport', false)
            ->assertSet('currency', 'MKD')
            ->assertSet('exchangeRate', '1')
            ->assertSet('lines.0.vat_treatment', 'standard')
            ->assertSet('lines.0.vat_rate', '18.00');
    }

    public function test_an_individual_without_the_tick_keeps_the_existing_foreign_flow(): void
    {
        Role::findOrCreate('admin');
        $company = Company::factory()->create(['type' => 'individual']);
        $partner = Partner::factory()->for($company)->create(['invoice_language' => 'en']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->form($company, $admin)
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-10-28')
            ->set('dueDate', '2026-11-10')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->line('0.00'))
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertFalse($invoice->is_export);
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(InvoiceLanguage::EN, $invoice->language);
    }
}
