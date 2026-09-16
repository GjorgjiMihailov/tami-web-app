<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
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

class SalesInvoiceScanChecksTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
        return Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'))
            ->call('readScan');
    }

    public function test_a_foreign_seller_tax_id_warns_about_the_wrong_file(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080099999999',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = $this->read($company)->get('scanWarnings');

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('влезна', implode(' ', $warnings));
    }

    public function test_a_matching_seller_tax_id_does_not_warn(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertStringNotContainsString('влезна', implode(' ', $this->read($company)->get('scanWarnings')));
    }

    public function test_an_unknown_buyer_is_offered_for_creation(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Нов Купувач ДООЕЛ',
            buyerTaxId: '4080055555555',
            buyerStreetAddress: 'Партизанска',
            buyerStreetNumber: '10',
            buyerPostalCode: '1000',
            buyerCity: 'Скопје',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $this->assertSame('Нов Купувач ДООЕЛ', $component->get('suggestedPartner')['name']);
        $this->assertSame('', $component->get('partnerId'));

        // Ништо не смее да влезе во шифрарникот без клик.
        $this->assertDatabaseMissing('partners', ['tax_id' => '4080055555555']);

        $component->call('createSuggestedPartner');

        $this->assertDatabaseHas('partners', [
            'company_id' => $company->id,
            'tax_id' => '4080055555555',
            'name' => 'Нов Купувач ДООЕЛ',
            'city' => 'Скопје',
        ]);

        $partner = Partner::where('tax_id', '4080055555555')->first();
        $component->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_known_buyer_is_selected_and_not_offered(): void
    {
        $company = $this->company();
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080055555555']);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->read($company)
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_total_that_does_not_add_up_warns(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            // 1 × 1000 + 18% = 1180, а на хартијата пишува 1500.
            printedTotal: '1500.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('1500.00', $warnings);
        $this->assertStringContainsString('1180.00', $warnings);
    }

    public function test_a_total_within_one_denar_does_not_warn(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.50',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertSame([], $this->read($company)->get('scanWarnings'));
    }
}
