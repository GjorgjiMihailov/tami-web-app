<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoiceLine;
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

    public function test_a_buyer_tax_id_with_a_prefix_finds_the_existing_partner(): void
    {
        $company = $this->company();
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080055555555']);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            // Токму она што скенот го враќа кога на хартијата пишува „МК".
            buyerTaxId: 'МК 4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        // Ненајден партнер значи понуден дупликат, а неговото неканонско ЕДБ
        // потоа заминува кон УЈП како купувач.
        $this->read($company)
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_partner_stored_with_a_prefix_is_found_by_plain_digits(): void
    {
        $company = $this->company();
        $partner = Partner::factory()->for($company)->create(['tax_id' => 'МК4080055555555']);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        // Шифрарникот не е чист по претпоставка — се споредува нормализирано
        // спрема нормализирано, на двете страни.
        $this->read($company)
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_an_unreadable_printed_total_warns_that_nothing_was_compared(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: null,
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        // Најсилната проверка воопшто не се случила — тоа мора да се види.
        $this->assertStringContainsString('Вкупниот износ не се прочита', $warnings);
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

    public function test_a_malformed_line_amount_warns_instead_of_crashing(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.00',
            // Илјадници одвоени со точка, запирка наместо децимална точка —
            // токму она што bcmath и decimal-кастот на моделите го фрлаат со
            // исклучок наместо да го прочитаат.
            lines: [new ScannedInvoiceLine('Услуга', '1', '1.180,00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('не можеа да се прочитаат', $warnings);
        // Не смее да се прикаже измислена бројка добиена од погрешно
        // парсирана низа (пр. „1.180,00" превртено во float дава 1.18).
        $this->assertStringNotContainsString('1.18', $warnings);
    }

    public function test_an_unusable_printed_total_warns_instead_of_crashing(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: 'N/A',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('не можеа да се прочитаат', $warnings);
    }

    public function test_the_computed_total_matches_the_saved_invoice_rounding(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            // Намерно погрешно вкупно, за проверката да предупреди и да ја
            // покаже точната пресметана бројка во пораката.
            printedTotal: '90.00',
            lines: [new ScannedInvoiceLine('Услуга', '3', '33.335', '0')],
        );

        // Истата аритметика што ја користи и зачувана фактура — decimal-кастот
        // прво го заокружува единечната цена (33.335 → 33.34), па дури тогаш
        // множи со количината. Наивно bcmul пред заокружување би дало 100.01.
        $expectedLine = new SalesInvoiceLine(['quantity' => '3', 'unit_price' => '33.335', 'vat_rate' => '0']);
        $expected = bcadd($expectedLine->lineTotal(), $expectedLine->vatAmount(), 2);
        $this->assertSame('100.02', $expected);

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString($expected, $warnings);
    }

    public function test_an_unnamed_buyer_is_not_created(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: null,
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $component->call('createSuggestedPartner')
            ->assertHasErrors(['suggestedPartner.name']);

        $this->assertDatabaseMissing('partners', ['tax_id' => '4080055555555']);
        $component->assertSet('partnerId', '')
            ->assertSet('suggestedPartner.tax_id', '4080055555555');
    }

    public function test_a_failed_read_after_a_successful_one_clears_the_suggested_partner(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Нов Купувач ДООЕЛ',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);
        $component->assertSet('suggestedPartner.name', 'Нов Купувач ДООЕЛ');

        FakeScannedInvoiceReader::$next = null;
        FakeScannedInvoiceReader::$throws = new \RuntimeException('симулиран пад на читањето');

        $component->set('scanFile', UploadedFile::fake()->create('faktura2.pdf', 200, 'application/pdf'))
            ->call('readScan')
            ->assertHasErrors('scanFile')
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_too_long_address_is_not_created(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Нов Купувач ДООЕЛ',
            buyerTaxId: '4080055555555',
            // 300 знаци — над max:255 за street_address колоната.
            buyerStreetAddress: str_repeat('А', 300),
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $component->call('createSuggestedPartner')
            ->assertHasErrors(['suggestedPartner.street_address']);

        $this->assertDatabaseMissing('partners', ['tax_id' => '4080055555555']);
        $component->assertSet('partnerId', '')
            ->assertSet('suggestedPartner.tax_id', '4080055555555');

        // Не е доволно грешката да стои само во error bag-от — мора да се
        // прикаже и на екранот, инаку кликот изгледа скршен, а не одбиен.
        $message = $component->instance()->getErrorBag()->first('suggestedPartner.street_address');
        $this->assertNotEmpty($message);
        $component->assertSee($message);
    }

    /**
     * Вистински СТП документ, качен кај фирмата-купувач: моделот го врати
     * нејзиното ЕДБ меѓу страните. Тоа мора да се препознае како влезна
     * фактура — и формата не смее да ѝ понуди на фирмата да се внесе самата
     * себе како партнер.
     */
    public function test_when_this_company_is_the_buyer_it_warns_and_offers_no_partner(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4028003136007',
            sellerName: 'СТП дооел',
            buyerName: 'Фирмата самата',
            buyerTaxId: 'МК4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);
        $warnings = implode(' ', $component->get('scanWarnings'));

        $this->assertStringContainsString('КУПУВАЧ', $warnings);
        $this->assertStringContainsString('влезна', $warnings);
        $component->assertSet('suggestedPartner', null);
    }

    /**
     * Врз вистински скен ЕДБ-то на издавачот се врати пресечено — „MK4028003“.
     * Тоа е „не знам", не „друга фирма". Лажна тревога врз исправна фактура
     * учи луѓе да не ги читаат предупредувањата.
     */
    public function test_a_truncated_seller_tax_id_is_treated_as_unread_not_as_another_firm(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: 'MK4080012',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('не можеше да се прочита', $warnings);
        $this->assertStringNotContainsString('друга фирма', $warnings);
    }

    /**
     * МПИН декларација нема ЕДБ на продавач воопшто. Порано упатството го
     * „пополнуваше" со ЕДБ-то на фирмата и проверката молчеше.
     */
    public function test_a_missing_seller_tax_id_warns_instead_of_passing_silently(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            buyerName: 'Некое лице',
            buyerTaxId: '5080022511086',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('не можеше да се прочита', $warnings);
    }

    public function test_another_issuer_is_named_in_the_warning(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4020012521206',
            sellerName: 'ВЕБВИЛ-СТУДИО ДООЕЛ',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('друга фирма', $warnings);
        $this->assertStringContainsString('ВЕБВИЛ-СТУДИО ДООЕЛ', $warnings);
    }

    /**
     * Вистински PDF со четири фактури: прочитана беше само првата, а другите
     * три исчезнаа без збор — издадени, а никогаш внесени.
     */
    public function test_a_file_with_several_invoices_warns_that_only_the_first_was_read(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            invoiceCount: 4,
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('4 фактури', $warnings);
        $this->assertStringContainsString('само првата', $warnings);
    }

    public function test_a_single_invoice_file_raises_no_count_warning(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            invoiceCount: 1,
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertSame([], $this->read($company)->get('scanWarnings'));
    }

    /**
     * Вистински СТП документ гледан од Солид Граунд: моделот одговори „продавач",
     * а во истиот одговор правилно го стави нашето ЕДБ кај купувачот. ЕДБ-то на
     * купувачот мора да победи над погрешниот одговор за улогата.
     */
    public function test_our_tax_id_on_the_buyer_side_beats_a_wrong_role_answer(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            ourCompanyRole: 'seller',
            sellerName: 'СТП',
            buyerName: 'Фирмата самата',
            buyerTaxId: 'МК4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $this->assertStringContainsString('КУПУВАЧ', implode(' ', $component->get('scanWarnings')));
        $component->assertSet('suggestedPartner', null);
    }

    /**
     * Вистинска MyGPM фактура: моделот го стави ИСТИОТ број во двете страни.
     * Тогаш бројките не докажуваат ништо — инаку, ако случајно е нашиот, секоја
     * исправна излезна фактура би била прогласена за влезна.
     */
    public function test_the_same_number_on_both_sides_is_not_proof_of_anything(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            ourCompanyRole: 'seller',
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertSame([], $this->read($company)->get('scanWarnings'));
    }

    /**
     * Вистинска MyGPM фактура: улогата точна („продавач"), ЕДБ-то на продавачот
     * погрешно (купувачовото). Со потпирање на ЕДБ-то ова беше лажна тревога.
     */
    public function test_a_correct_role_answer_ignores_a_misread_seller_tax_id(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            ourCompanyRole: 'seller',
            sellerTaxId: '4080055555555',
            buyerTaxId: '4080066666666',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertSame([], $this->read($company)->get('scanWarnings'));
    }

    public function test_a_buyer_role_answer_warns_that_the_invoice_is_incoming(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            ourCompanyRole: 'buyer',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $this->assertStringContainsString('влезна', implode(' ', $component->get('scanWarnings')));
        $component->assertSet('suggestedPartner', null);
    }

    public function test_an_absent_company_warns_that_the_file_may_be_wrong(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            ourCompanyRole: 'absent',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertStringContainsString('не се спомнува', implode(' ', $this->read($company)->get('scanWarnings')));
    }

    public function test_a_document_that_is_not_an_invoice_says_so(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            invoiceCount: 0,
            buyerName: 'АНА МАРИЈА МИХАИЛОВА',
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('не изгледа како фактура', $warnings);
    }
}
