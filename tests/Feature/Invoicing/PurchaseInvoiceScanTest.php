<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
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

class PurchaseInvoiceScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    private function company(): Company
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $company;
    }

    private function read(Company $company)
    {
        return Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'))
            ->call('readScan');
    }

    public function test_a_scan_fills_the_form_and_matches_the_supplier_and_known_items(): void
    {
        $company = $this->company();
        $supplier = Partner::factory()->create(['company_id' => $company->id, 'tax_id' => '4030999111222']);
        $known = Item::factory()->create(['company_id' => $company->id, 'name' => 'Брашно Т-500']);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4030999111222',
            buyerTaxId: '4080012345678',
            invoiceNumber: 'F-77',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-31',
            lines: [
                new ScannedInvoiceLine('брашно т-500', '10', '30.00', '5'),
                new ScannedInvoiceLine('Нов производ', '2', '100.00', '18'),
            ],
        );

        $component = $this->read($company);

        $component->assertSet('supplierInvoiceNumber', 'F-77')
            ->assertSet('partnerId', (string) $supplier->id)
            ->assertSet('lines.0.item_id', (string) $known->id)
            ->assertSet('lines.1.item_id', '')
            ->assertSet('suggestedPartner', null);
    }

    public function test_an_unknown_supplier_is_offered_as_a_new_partner(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: 'Добавувач ДООЕЛ',
            sellerTaxId: '4030999111222',
            buyerTaxId: '4080012345678',
            lines: [new ScannedInvoiceLine('Стока', '1', '10.00', '18')],
        );

        $this->read($company)->call('createSuggestedPartner')->assertSet('suggestedPartner', null);

        $this->assertDatabaseHas('partners', ['company_id' => $company->id, 'tax_id' => '4030999111222']);
    }

    public function test_an_invoice_we_issued_warns_that_it_is_not_incoming(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            ourCompanyRole: 'seller',
            lines: [new ScannedInvoiceLine('Услуга', '1', '10.00', '18')],
        );

        $warnings = $this->read($company)->get('scanWarnings');

        $this->assertStringContainsString('излезна', implode(' ', $warnings));
    }

    public function test_a_line_missing_from_items_can_be_entered_as_a_stock_item(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            buyerTaxId: '4080012345678',
            lines: [new ScannedInvoiceLine('Нов производ', '2', '100.00', '18')],
        );

        $component = $this->read($company)->call('addLineAsItem', 0);

        $item = Item::where('company_id', $company->id)->where('name', 'Нов производ')->first();

        $this->assertNotNull($item);
        $this->assertSame('product', $item->type);
        $this->assertSame((string) $item->id, $component->get('lines.0.item_id'));

        // Втор клик не создава дупликат.
        $component->call('addLineAsItem', 0);
        $this->assertSame(1, Item::where('company_id', $company->id)->where('name', 'Нов производ')->count());
    }

    public function test_a_client_cannot_read_scans(): void
    {
        $company = Company::factory()->create();
        Role::findOrCreate('internal_client');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        $this->assertFalse(Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])->instance()->canReadScans());
    }
}
