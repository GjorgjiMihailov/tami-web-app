<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceImportCost;
use App\Models\User;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedCustomsItem;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Services\Invoicing\ScannedInvoiceReadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeCustomsDeclarationReader;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class PurchaseInvoiceImportScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        FakeCustomsDeclarationReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
        $this->app->bind(CustomsDeclarationReader::class, FakeCustomsDeclarationReader::class);
    }

    private function company(): Company
    {
        $company = Company::factory()->create(['tax_id' => '4000000000001']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $company;
    }

    private function ecd(): ScannedCustomsDeclaration
    {
        return new ScannedCustomsDeclaration(
            declarationNumber: '26MKIM99990001C111',
            date: '2026-03-04',
            importerTaxId: 'MK4000000000001',
            declarantName: 'ТЕСТ ШПЕДИТЕР',
            currency: 'EUR',
            invoiceTotalForeign: '100.00',
            exchangeRate: '61.6950',
            totalDuty: '50',
            totalVat: '100',
            referencedInvoiceNumbers: ['T-1/26'],
            items: [
                new ScannedCustomsItem('61091000', 'a', '60.00', '3700', ['A00' => '30', 'B00' => '60']),
                new ScannedCustomsItem('61091000', 'b', '40.00', '2470', ['A00' => '20', 'B00' => '40']),
            ],
        );
    }

    private function foreignInvoice(): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerTaxId: '107545537',
            sellerName: 'FOREIGN DOO',
            buyerTaxId: '4000000000001',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '100.00',
            ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );
    }

    private function forwarderInvoice(string $net = '3070.00'): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            invoiceNumber: '2600000286',
            currency: 'MKD',
            lines: [new ScannedInvoiceLine('Посредување', '1', $net, '18')],
        );
    }

    private function files()
    {
        return [
            'ecdFile' => UploadedFile::fake()->create('ecd.pdf', 200, 'application/pdf'),
            'importInvoiceFile' => UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'),
            'forwarderFile' => UploadedFile::fake()->create('shpediter.pdf', 200, 'application/pdf'),
        ];
    }

    public function test_the_ecd_alone_fills_declaration_fields_and_aggregated_tariff_lines(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $this->files()['ecdFile'])
            ->call('readImportDocuments')
            ->assertSet('isImport', true)
            ->assertSet('customsDeclarationNumber', '26MKIM99990001C111')
            ->assertSet('importDate', '2026-03-04')
            ->assertSet('importCurrencyCode', 'EUR')
            ->assertSet('importExchangeRate', '61.6950')
            ->assertCount('tariffLines', 1)
            ->assertSet('tariffLines.0.tariff_code', '61091000')
            ->assertSet('tariffLines.0.foreign_amount', '100.00')
            ->assertSet('tariffLines.0.customs_duty', '50.00')
            ->assertSet('tariffLines.0.vat_amount', '100.00')
            ->assertSet('importScanWarnings', []);
    }

    public function test_all_three_documents_use_the_ecd_rate_and_move_the_transport_to_costs(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        // Читачот се вика по ред: прво фактурата од добавувач, потоа шпедитерската.
        FakeScannedInvoiceReader::$queue = [$this->foreignInvoice(), $this->forwarderInvoice()];

        $files = $this->files();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->set('forwarderFile', $files['forwarderFile'])
            ->call('readImportDocuments')
            ->assertSet('supplierInvoiceNumber', 'T-1/26')
            // 12.50 EUR * 61.6950 = 771.1875 -> 771.19
            ->assertSet('lines.0.unit_price', '771.19')
            ->assertSet('lines.0.description', 'Рукавици зимски')
            ->assertSet('lines.0.vat_rate', '0')
            ->assertCount('lines', 1)
            ->assertCount('importCosts', 2)
            ->assertSet('importCosts.0.payee_name', 'FOREIGN DOO')
            ->assertSet('importCosts.0.foreign_amount', '100.00')
            ->assertSet('importCosts.0.base_amount', '6169.50')
            ->assertSet('importCosts.0.source', 'invoice')
            ->assertSet('importCosts.1.payee_name', 'ТЕСТ ШПЕДИТЕР ДООЕЛ')
            ->assertSet('importCosts.1.base_amount', '3070.00')
            ->assertSet('importCosts.1.source', 'forwarder');
    }

    public function test_the_forwarder_invoice_adds_one_cost_row_and_rereading_replaces_it(): void
    {
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->forwarderInvoice();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.payee_name', 'ТЕСТ ШПЕДИТЕР ДООЕЛ')
            ->assertSet('importCosts.0.base_amount', '3070.00')
            ->assertSet('importCosts.0.vat_amount', '552.60')
            ->assertSet('importCosts.0.source', 'forwarder');

        FakeScannedInvoiceReader::$next = $this->forwarderInvoice('4000.00');

        $component->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.base_amount', '4000.00')
            ->assertSet('importCosts.0.vat_amount', '720.00');
    }

    public function test_rereading_the_forwarder_on_a_reopened_draft_replaces_the_saved_row_not_doubles_it(): void
    {
        $company = $this->company();
        $invoice = PurchaseInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => Partner::factory()->create(['company_id' => $company->id])->id,
            'is_import' => true,
            'import_currency_code' => 'EUR',
            'import_exchange_rate' => '61.6950',
        ]);
        PurchaseInvoiceImportCost::factory()->create([
            'purchase_invoice_id' => $invoice->id,
            'payee_name' => 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            'reference_number' => '2600000286',
            'base_amount' => '3070.00',
            'vat_amount' => '552.60',
        ]);
        FakeScannedInvoiceReader::$next = $this->forwarderInvoice('4000.00');

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertCount('importCosts', 1)
            ->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.base_amount', '4000.00')
            ->assertSet('importCosts.0.source', 'forwarder');
    }

    public function test_rereading_the_invoice_replaces_its_charge_rows(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();
        $files = $this->files();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.base_amount', '6169.50');

        // Курсот од првото читање останува во формата; втората фактура има поскап превоз.
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '107545537',
            sellerName: 'FOREIGN DOO',
            buyerTaxId: '4000000000001',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '200.00',
            ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '200.00', '0', 'charge'),
            ],
        );

        $component->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            // 200.00 EUR * 61.6950 = 12339.00
            ->assertSet('importCosts.0.base_amount', '12339.00');
    }

    public function test_an_mkd_invoice_is_applied_without_conversion(): void
    {
        $company = $this->company();
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: 'ДОМАШЕН ДООЕЛ',
            invoiceNumber: 'D-5',
            invoiceDate: '2026-02-24',
            currency: 'MKD',
            ourCompanyRole: 'buyer',
            lines: [new ScannedInvoiceLine('Стока', '2', '500.00', '18', 'goods')],
        );

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSet('isImport', true)
            ->assertSet('supplierInvoiceNumber', 'D-5')
            ->assertCount('lines', 1)
            ->assertSet('lines.0.unit_price', '500.00')
            ->assertSet('lines.0.vat_rate', '18')
            ->assertSet('importInvoiceFile', null)
            ->assertHasNoErrors();
    }

    public function test_a_foreign_invoice_without_an_ecd_and_without_a_rate_keeps_the_upload_and_asks_for_the_rate(): void
    {
        Http::fake(['nbrm.mk/*' => Http::response('boom', 500)]);
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertHasErrors('importExchangeRate')
            ->assertSet('supplierInvoiceNumber', '')
            ->assertCount('importCosts', 0);

        $this->assertNotNull($component->get('importInvoiceFile'));
        $this->assertSame('', $component->get('lines.0.description'));

        // Со рачно внесен курс истиот прикачен фајл се чита без повторно прикачување.
        $component->set('importExchangeRate', '61.5')
            ->call('readImportDocuments')
            ->assertHasNoErrors('importExchangeRate')
            ->assertSet('lines.0.unit_price', '768.75')
            ->assertSet('importInvoiceFile', null);
    }

    public function test_without_an_ecd_the_rate_comes_from_nbrm_on_the_invoice_date_with_a_warning(): void
    {
        Http::fake(['nbrm.mk/*' => Http::response([['oznaka' => 'EUR', 'sreden' => 61.7, 'nomin' => 1, 'datum' => '2026-02-24T00:00:00']], 200)]);
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSet('importExchangeRate', '61.7')
            ->assertSet('lines.0.unit_price', '771.25')
            ->assertSet('importScanWarnings', fn (array $warnings) => collect($warnings)->contains(fn ($w) => str_contains($w, 'НБРМ')));
    }

    public function test_the_nbrm_warning_is_rendered_on_the_form_after_a_read_without_an_ecd(): void
    {
        Http::fake(['nbrm.mk/*' => Http::response([['oznaka' => 'EUR', 'sreden' => 61.7, 'nomin' => 1, 'datum' => '2026-02-24T00:00:00']], 200)]);
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        // „НБРМ“ веќе стои во статичките ознаки (Курс (НБРМ на датумот), ↻ НБРМ),
        // па се проверува самата реченица од предупредувањето.
        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSee('Провери пред да зачуваш')
            ->assertSee('курсот е од НБРМ на датумот на фактурата');
    }

    public function test_a_failing_reader_shows_an_error_and_keeps_the_rest(): void
    {
        Http::fake(['nbrm.mk/*' => Http::response([['oznaka' => 'EUR', 'sreden' => 61.7, 'nomin' => 1, 'datum' => '2026-02-24T00:00:00']], 200)]);
        $company = $this->company();
        FakeCustomsDeclarationReader::$throws = new ScannedInvoiceReadException('x');
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();
        $files = $this->files();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertHasErrors('ecdFile')
            ->assertSet('supplierInvoiceNumber', 'T-1/26');
    }

    public function test_reading_with_no_file_shows_an_error(): void
    {
        $company = $this->company();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('readImportDocuments')
            ->assertHasErrors('importDocuments');
    }

    public function test_reading_requires_the_scan_permission(): void
    {
        // Клиент (не админ/сметководител) со поставен клуч: влегува во формата,
        // но читањето му е забрането. Корисник без никаква улога не стигнува
        // ни до акцијата — mount() му враќа 403.
        $company = Company::factory()->create();
        Role::findOrCreate('internal_client');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('readImportDocuments')
            ->assertForbidden();
    }

    public function test_reading_is_forbidden_without_an_api_key(): void
    {
        $company = $this->company();
        config(['services.anthropic.key' => '']);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('readImportDocuments')
            ->assertForbidden();
    }

    public function test_add_all_unknown_lines_as_items_creates_each_once_and_reuses_existing(): void
    {
        $company = $this->company();
        $known = Item::factory()->create(['company_id' => $company->id, 'name' => 'Позната', 'code' => 'A-0001']);

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [
                ['item_id' => '', 'account_id' => '', 'description' => 'Позната', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => 'Нова една', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => 'Нова една', 'quantity' => '2', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
            ])
            ->call('addAllUnknownLinesAsItems');

        $this->assertSame((string) $known->id, $component->get('lines.0.item_id'));
        $this->assertNotSame('', $component->get('lines.1.item_id'));
        $this->assertSame($component->get('lines.1.item_id'), $component->get('lines.2.item_id'));
        $this->assertSame('', $component->get('lines.3.item_id'));
        $this->assertSame(2, Item::where('company_id', $company->id)->count());
    }
}
