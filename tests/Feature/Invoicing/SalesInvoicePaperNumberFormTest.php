<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePaperNumberFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    private function fill($component, Company $company, Partner $partner)
    {
        return $component
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines', [[
                'item_id' => '',
                'description' => 'Услуга',
                'quantity' => '1',
                'unit_price' => '1000.00',
                'vat_rate' => '18.00',
                'vat_treatment' => 'standard',
            ]]);
    }

    public function test_a_paper_number_is_saved_on_the_draft(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', [
            'company_id' => $company->id,
            'invoice_number_formatted' => '2026/45',
            'status' => 'draft',
        ]);
    }

    public function test_an_empty_paper_number_leaves_the_column_null(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SalesInvoice::where('company_id', $company->id)->first()->invoice_number_formatted);
    }

    public function test_a_duplicate_paper_number_in_the_same_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-05-01',
            'invoice_number_formatted' => '2026/45',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasErrors('paperNumber');
    }

    public function test_the_same_paper_number_in_another_year_is_allowed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2025-05-01',
            'invoice_number_formatted' => '001',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '001')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_editing_a_draft_keeps_its_paper_number_in_the_field(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_number_formatted' => '2026/45',
        ]);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSet('paperNumber', '2026/45');
    }
}
