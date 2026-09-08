<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceNumberFormatTest extends TestCase
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

    private function draft(Company $company, string $date = '2026-03-10'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => $date,
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    public function test_confirming_freezes_the_formatted_number(): void
    {
        // CompanyObserver го сее целиот официјален контен план при создавање,
        // па секој конто што confirm() го бара постои.
        $company = Company::factory()->create([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);

        $this->assertSame('00001-26', $invoice->fresh()->invoice_number_formatted);
        $this->assertSame('00001-26', $invoice->fresh()->formattedNumber());
    }

    public function test_changing_the_format_later_does_not_move_a_confirmed_invoice(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);
        $this->assertSame('2026/1', $invoice->fresh()->formattedNumber());

        $company->update([
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('2026/1', $invoice->fresh()->formattedNumber());
    }

    public function test_a_draft_has_no_number(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->assertNull($this->draft($company)->formattedNumber());
    }

    public function test_a_row_with_no_frozen_text_falls_back_to_the_current_settings(): void
    {
        $company = Company::factory()->create(['invoice_number_padding' => 4]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 7,
            'invoice_number_formatted' => null,
        ]);

        $this->assertSame('2026/0007', $invoice->formattedNumber());
    }
}
