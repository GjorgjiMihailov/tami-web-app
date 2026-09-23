<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Services\Invoicing\ScannedInvoiceReadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class SalesInvoiceScanReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    private function actAs(Company $company, string $role): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($role);

        // Сметководителот ја гледа фирмата само преку врската accountants —
        // на клиентот му е доволно company_id. Без ова mount() враќа 403 и
        // тестот паѓа од погрешна причина.
        if ($role === 'accountant') {
            $company->accountants()->attach($user->id);
        }

        $this->actingAs($user);

        return $user;
    }

    private function scan(): UploadedFile
    {
        return UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf');
    }

    public function test_reading_a_scan_fills_the_form(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080087654321']);
        $this->actAs($company, 'admin');

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: $partner->name,
            buyerTaxId: '4080087654321',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            currency: 'MKD',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Консултантски услуги', '1', '1000.00', '18')],
        );

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', $this->scan())
            ->call('readScan')
            ->assertSet('scanRead', true)
            ->assertSet('paperNumber', '2026/45')
            ->assertSet('invoiceDate', '2026-03-01')
            ->assertSet('dueDate', '2026-03-15')
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('lines.0.description', 'Консултантски услуги')
            ->assertSet('lines.0.quantity', '1')
            ->assertSet('lines.0.unit_price', '1000.00')
            ->assertSet('lines.0.vat_rate', '18')
            ->assertSet('lines.0.vat_treatment', 'standard')
            // Ставките од скен се слободен текст: без артикл нема поместување
            // залиха и нема потреба од магацин.
            ->assertSet('lines.0.item_id', '')
            ->assertSet('warehouseId', '');
    }

    public function test_a_long_cyrillic_invoice_number_is_cut_by_characters(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $this->actAs($company, 'admin');

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            // Кирилица е по два бајта: сечење по бајти ја крши буквата на
            // граница и базата одбива таков текст.
            invoiceNumber: '2026/'.str_repeat('Ф', 50),
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $paperNumber = Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', $this->scan())
            ->call('readScan')
            ->get('paperNumber');

        $this->assertTrue(mb_check_encoding($paperNumber, 'UTF-8'));
        $this->assertSame(40, mb_strlen($paperNumber));
    }

    public function test_an_image_over_the_api_limit_is_refused_with_a_clear_message(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $this->actAs($company, 'admin');

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        // 8 МБ сурово стануваат ~10,7 МБ во base64 — над границата по слика.
        $component = Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('slika.jpg', 8000, 'image/jpeg'))
            ->call('readScan')
            ->assertHasErrors('scanFile')
            ->assertSet('scanRead', false);

        // Генеричката порака „не можев да ја прочитам" не му кажува на човекот
        // што да направи.
        $this->assertStringContainsString(
            'преголема',
            $component->instance()->getErrorBag()->first('scanFile')
        );
    }

    public function test_a_large_pdf_is_still_accepted(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $this->actAs($company, 'admin');

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        // Границата по слика не важи за документи — PDF останува како досега.
        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 8000, 'application/pdf'))
            ->call('readScan')
            ->assertHasNoErrors('scanFile')
            ->assertSet('scanRead', true);
    }

    public function test_a_failed_read_leaves_the_form_empty_and_reports_it(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'accountant');

        FakeScannedInvoiceReader::$throws = new ScannedInvoiceReadException('нема врска');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', $this->scan())
            ->call('readScan')
            ->assertSet('scanRead', false)
            ->assertSet('partnerId', '')
            ->assertHasErrors('scanFile');
    }

    public function test_a_client_cannot_read_scans(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'internal_client');

        $component = Livewire::test(SalesInvoiceForm::class, ['company' => $company]);

        $this->assertFalse($component->instance()->canReadScans());

        $component->set('scanFile', $this->scan())->call('readScan')->assertForbidden();
    }

    public function test_without_a_key_the_reader_is_switched_off(): void
    {
        config(['services.anthropic.key' => null]);

        $company = Company::factory()->create();
        $this->actAs($company, 'admin');

        $this->assertFalse(
            Livewire::test(SalesInvoiceForm::class, ['company' => $company])->instance()->canReadScans()
        );
    }

    public function test_the_upload_field_is_hidden_when_reading_is_unavailable(): void
    {
        config(['services.anthropic.key' => null]);

        $company = Company::factory()->create();
        $this->actAs($company, 'admin');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Прикачи скенирана фактура');
    }

    public function test_the_upload_field_is_shown_to_an_accountant_with_a_key(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'accountant');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSee('Прикачи скенирана фактура');
    }
}
