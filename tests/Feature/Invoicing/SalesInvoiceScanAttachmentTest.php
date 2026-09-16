<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class SalesInvoiceScanAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        Storage::fake('google');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    public function test_the_scan_is_attached_to_the_saved_invoice(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080055555555']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080055555555',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'))
            ->call('readScan')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertDatabaseHas('documents', [
            'company_id' => $company->id,
            'documentable_id' => $invoice->id,
            'original_filename' => 'faktura.pdf',
            'category' => 'Invoice',
        ]);
    }

    public function test_an_invoice_saved_without_a_scan_has_no_attachment(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines', [[
                'item_id' => '', 'description' => 'Услуга', 'quantity' => '1',
                'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('documents', 0);
    }
}
