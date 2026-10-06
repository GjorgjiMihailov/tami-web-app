<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Account;
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

    private function company(array $attributes = []): Company
    {
        $company = Company::factory()->create(['tax_id' => '4000000000001'] + $attributes);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $company;
    }

    private function ecd(): ScannedCustomsDeclaration
    {
        return new ScannedCustomsDeclaration(
            declarationNumber: '26MKIM00000001C000',
            date: '2026-03-04',
            importerTaxId: 'MK4000000000001',
            declarantName: 'ТЕСТ ШПЕДИТЕР',
            currency: 'EUR',
            invoiceTotalForeign: '100.00',
            exchangeRate: '61.5000',
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
            sellerTaxId: '1234567890',
            sellerName: 'FOREIGN DOO',
            buyerTaxId: '4000000000001',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '125.00',
            ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );
    }

    private function forwarderInvoice(string $net = '2000.00'): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            invoiceNumber: 'F-77/26',
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
            ->assertSet('customsDeclarationNumber', '26MKIM00000001C000')
            ->assertSet('importDate', '2026-03-04')
            ->assertSet('importCurrencyCode', 'EUR')
            ->assertSet('importExchangeRate', '61.5000')
            ->assertCount('tariffLines', 1)
            ->assertSet('tariffLines.0.tariff_code', '61091000')
            ->assertSet('tariffLines.0.foreign_amount', '100.00')
            ->assertSet('tariffLines.0.customs_duty', '50.00')
            ->assertSet('tariffLines.0.vat_amount', '100.00')
            ->assertSet('importScanWarnings', []);
    }

    public function test_all_three_documents_use_the_ecd_rate_and_keep_the_transport_as_a_660_line_and_a_cost(): void
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
            // 12.50 EUR * 61.5 = 768.75
            ->assertSet('lines.0.unit_price', '768.75')
            ->assertSet('lines.0.description', 'Рукавици зимски')
            ->assertSet('lines.0.vat_rate', '0')
            ->assertSet('lines.0.account_id', '')
            // Транспортот останува ставка на фактурата (долгот кон добавувачот = хартијата) на сметка 660:
            // 100.00 EUR * 61.5 = 6150.00, без артикл, ДДВ 0.
            ->assertCount('lines', 2)
            ->assertSet('lines.1.description', 'ТРОШКОВИ НА ТРАНСПОРТА')
            ->assertSet('lines.1.unit_price', '6150.00')
            ->assertSet('lines.1.vat_rate', '0')
            ->assertSet('lines.1.item_id', '')
            ->assertSet('lines.1.account_id', (string) Account::where('company_id', $company->id)->where('code', '6601')->value('id'))
            // ... а истиот износ е и ред „Увозни трошоци" за магацинската вредност.
            ->assertCount('importCosts', 2)
            ->assertSet('importCosts.0.payee_name', 'FOREIGN DOO')
            ->assertSet('importCosts.0.foreign_amount', '100.00')
            ->assertSet('importCosts.0.base_amount', '6150.00')
            ->assertSet('importCosts.0.source', 'invoice')
            ->assertSet('importCosts.1.payee_name', 'ТЕСТ ШПЕДИТЕР ДООЕЛ')
            ->assertSet('importCosts.1.base_amount', '2000.00')
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
            ->assertSet('importCosts.0.base_amount', '2000.00')
            ->assertSet('importCosts.0.vat_amount', '360.00')
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
            'import_exchange_rate' => '61.5000',
        ]);
        PurchaseInvoiceImportCost::factory()->create([
            'purchase_invoice_id' => $invoice->id,
            'payee_name' => 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            'reference_number' => 'F-77/26',
            'base_amount' => '2000.00',
            'vat_amount' => '360.00',
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
            ->assertSet('importCosts.0.base_amount', '6150.00');

        // Курсот од првото читање останува во формата; втората фактура има поскап превоз.
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '1234567890',
            sellerName: 'FOREIGN DOO',
            buyerTaxId: '4000000000001',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '225.00',
            ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '200.00', '0', 'charge'),
            ],
        );

        $component->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            // 200.00 EUR * 61.5 = 12300.00
            ->assertSet('importCosts.0.base_amount', '12300.00');
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
            ->assertSee('Курсот е од НБРМ на датумот на фактурата');
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

    private function importLine(string $description, string $accountId = '', string $vat = '0'): array
    {
        return ['item_id' => '', 'account_id' => $accountId, 'description' => $description, 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => $vat, 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false];
    }

    public function test_items_created_from_import_lines_get_the_company_default_vat_not_the_zero_of_the_import(): void
    {
        $company = $this->company(['is_vat_registered' => true]);

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('isImport', true)
            ->set('lines', [$this->importLine('Увозна една'), $this->importLine('Увозна две')])
            ->call('addAllUnknownLinesAsItems');

        $this->assertSame('18.00', Item::where('company_id', $company->id)->where('name', 'Увозна една')->value('vat_rate'));
        $this->assertSame('18.00', Item::where('company_id', $company->id)->where('name', 'Увозна две')->value('vat_rate'));

        $component->set('lines', [$this->importLine('Увозна три')])->call('addLineAsItem', 0);

        $this->assertSame('18.00', Item::where('company_id', $company->id)->where('name', 'Увозна три')->value('vat_rate'));
    }

    public function test_items_created_from_import_lines_get_zero_vat_for_a_company_outside_vat(): void
    {
        $company = $this->company(['is_vat_registered' => false]);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('isImport', true)
            ->set('lines', [$this->importLine('Увозна една', '', '18')])
            ->call('addAllUnknownLinesAsItems');

        $this->assertSame('0.00', Item::where('company_id', $company->id)->where('name', 'Увозна една')->value('vat_rate'));
    }

    public function test_a_non_import_line_still_keeps_its_own_vat_rate_on_the_new_item(): void
    {
        $company = $this->company(['is_vat_registered' => true]);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [$this->importLine('Домашна', '', '5')])
            ->call('addLineAsItem', 0);

        $this->assertSame('5.00', Item::where('company_id', $company->id)->where('name', 'Домашна')->value('vat_rate'));
    }

    public function test_add_all_unknown_skips_a_line_that_already_has_an_account(): void
    {
        $company = $this->company();
        $accountId = (string) Account::where('company_id', $company->id)->where('code', '6601')->value('id');

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [$this->importLine('Стока'), $this->importLine('ТРАНСПОРТ', $accountId)])
            ->call('addAllUnknownLinesAsItems');

        $this->assertNotSame('', $component->get('lines.0.item_id'));
        $this->assertSame('', $component->get('lines.1.item_id'));
        $this->assertSame($accountId, $component->get('lines.1.account_id'));
        $this->assertSame(0, Item::where('company_id', $company->id)->where('name', 'ТРАНСПОРТ')->count());
    }

    public function test_the_bulk_button_is_hidden_when_only_account_lines_remain(): void
    {
        $company = $this->company();
        $accountId = (string) Account::where('company_id', $company->id)->where('code', '6601')->value('id');

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [$this->importLine('ТРАНСПОРТ', $accountId)])
            ->assertDontSee('Внеси ги сите непознати како артикли');

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [$this->importLine('Стока')])
            ->assertSee('Внеси ги сите непознати како артикли');
    }

    public function test_an_unreadable_invoice_currency_falls_back_to_the_ecd_currency_and_rate(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        $invoice = $this->foreignInvoice();
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: $invoice->sellerName, buyerTaxId: '4000000000001', invoiceNumber: 'T-1/26', invoiceDate: '2026-02-24',
            currency: null, printedTotal: '125.00', ourCompanyRole: 'buyer', lines: $invoice->lines,
        );
        $files = $this->files();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            // 12.50 * 61.5 по курсот од ЕЦД, не „како денари“.
            ->assertSet('lines.0.unit_price', '768.75')
            ->assertCount('importCosts', 1)
            ->assertSet('importScanWarnings', fn (array $w) => ! collect($w)->contains(fn ($x) => str_contains($x, 'Валутата на фактурата не е прочитана')));
    }

    public function test_an_unreadable_invoice_currency_without_an_ecd_is_applied_as_is_with_a_warning(): void
    {
        $company = $this->company();
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: 'FOREIGN DOO', buyerTaxId: '4000000000001', invoiceNumber: 'T-1/26', invoiceDate: '2026-02-24',
            currency: null, ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSet('lines.0.unit_price', '12.50')
            ->assertCount('lines', 2)
            ->assertSet('lines.1.unit_price', '100.00')
            ->assertSet('lines.1.account_id', '')
            ->assertCount('importCosts', 0)
            ->assertSet('importScanWarnings', fn (array $w) => collect($w)->contains(fn ($x) => str_contains($x, 'Валутата на фактурата не е прочитана — износите се третирани како денари')));
    }

    public function test_rereading_the_invoice_in_denars_removes_the_earlier_transport_cost_row(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();
        $files = $this->files();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: 'ДОМАШЕН ДООЕЛ', invoiceNumber: 'D-5', invoiceDate: '2026-02-24', currency: 'MKD', ourCompanyRole: 'buyer',
            lines: [new ScannedInvoiceLine('Стока', '2', '500.00', '18', 'goods')],
        );

        $component->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 0);
    }

    public function test_an_oversized_image_in_a_slot_is_rejected_without_calling_the_reader(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        FakeScannedInvoiceReader::$next = $this->forwarderInvoice();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', UploadedFile::fake()->create('ecd.jpg', 7000, 'image/jpeg'))
            ->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertHasErrors('ecdFile')
            // ЕЦД не е прочитана (читачот не е повикан), шпедитерската е.
            ->assertSet('customsDeclarationNumber', '')
            ->assertCount('tariffLines', 0)
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.source', 'forwarder');
    }

    public function test_an_oversized_image_alone_reads_nothing_and_does_not_mark_the_invoice_as_import(): void
    {
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', UploadedFile::fake()->create('faktura.png', 7000, 'image/png'))
            ->call('readImportDocuments')
            ->assertHasErrors('importInvoiceFile')
            ->assertSet('isImport', false)
            ->assertSet('supplierInvoiceNumber', '');
    }

    public function test_any_failure_in_one_reader_is_reported_on_its_slot_and_does_not_lose_the_others(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$throws = new \RuntimeException('parsing crash');
        FakeScannedInvoiceReader::$next = $this->forwarderInvoice();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $this->files()['ecdFile'])
            ->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertHasErrors('ecdFile')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.source', 'forwarder');
    }
}
