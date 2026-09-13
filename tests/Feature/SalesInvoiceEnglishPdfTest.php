<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceEnglishPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function invoice(array $attributes = [], array $companyAttributes = []): SalesInvoice
    {
        $company = Company::factory()->create(array_merge([
            'name' => 'Stefan Kotev',
            'type' => 'individual',
            'is_vat_registered' => false,
            'tax_id' => '4080012345678',
        ], $companyAttributes));
        $company->bankAccounts()->create([
            'bank_name' => 'Komercijalna banka',
            'account_number' => '300000000000123',
            'position' => 0,
        ]);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'Acme Ltd',
            'address' => '5 Market Street',
        ]);

        $invoice = SalesInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 7,
            'invoice_number_formatted' => '2026/7',
            'invoice_date' => '2026-09-13',
            'due_date' => '2026-09-30',
        ], $attributes));

        $invoice->lines()->create([
            'description' => 'Consulting services',
            'quantity' => '2',
            'unit_price' => '750.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        return $invoice->fresh(['lines', 'partner', 'company.bankAccounts']);
    }

    private function render(SalesInvoice $invoice): string
    {
        return view('pdf.sales-invoice', ['invoice' => $invoice])->render();
    }

    public function test_a_denar_invoice_still_prints_every_macedonian_heading(): void
    {
        // Ова е заштитата на веќе живата фактура. Ако падне, нешто на
        // испечатената денарска хартија се сменило.
        $html = $this->render($this->invoice());

        foreach ([
            'ФАКТУРА', 'Датум на фактура', 'Датум на доспевање', 'Издавач', 'Купувач',
            'ЕДБ', 'Р.б.', 'Опис', 'Кол.', 'Ед. цена', 'Вкупно', 'Начин на плаќање',
            'Назив на примач', 'Банка на примач', 'Сметка', 'Износ', 'Цел на дознака',
            'Основа', 'ДДВ', 'За доплата', 'ОВЛАСТЕНО ЛИЦЕ', 'ПРИМИЛ',
            'Фирмава не е ДДВ обврзник.',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "Недостасува: {$heading}");
        }
    }

    public function test_a_denar_invoice_still_prints_macedonian_numbers_and_dates(): void
    {
        $html = $this->render($this->invoice());

        $this->assertStringContainsString('1.500,00 ден', $html);
        $this->assertStringContainsString('13.09.2026', $html);
        $this->assertStringNotContainsString('1,500.00', $html);
    }

    public function test_an_english_invoice_prints_english_headings(): void
    {
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        foreach ([
            'INVOICE', 'Invoice date', 'Due date', 'Seller', 'Buyer', 'Tax no.',
            'No.', 'Description', 'Qty', 'Unit price', 'Total', 'Payment details',
            'Beneficiary', 'Amount', 'Payment reference', 'Subtotal', 'Balance due',
            'AUTHORISED SIGNATURE', 'RECEIVED BY', 'Not registered for VAT.',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "Недостасува: {$heading}");
        }

        $this->assertStringNotContainsString('ФАКТУРА', $html);
        $this->assertStringNotContainsString('Издавач', $html);
        $this->assertStringNotContainsString('ОВЛАСТЕНО ЛИЦЕ', $html);
    }

    public function test_an_english_invoice_prints_amounts_in_the_invoice_currency(): void
    {
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        $this->assertStringContainsString('1,500.00 EUR', $html);
        $this->assertStringContainsString('13 Sep 2026', $html);
        $this->assertStringNotContainsString('ден', $html);
        $this->assertStringNotContainsString('1.500,00', $html);
    }

    public function test_an_english_invoice_never_prints_the_denar_countervalue(): void
    {
        // Договорена одлука: хартијата е чисто во валутата на фактурата.
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        $this->assertStringNotContainsString('61.50', $html);
        $this->assertStringNotContainsString('92,250', $html);
        $this->assertStringNotContainsString('MKD', $html);
    }

    public function test_the_pdf_route_renders_real_pdf_bytes_for_an_english_invoice(): void
    {
        // dompdf 3.1.6 нема flex — „HTML-от изгледа добро“ не докажува ништо.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$invoice->company, $invoice]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // Барив dompdf ->download() враќа обичен Illuminate\Http\Response (не
        // StreamedResponse) — содржината се чита со getContent(), не streamedContent().
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_vat_registered_english_invoice_prints_the_vat_columns_in_english(): void
    {
        $invoice = $this->invoice(
            ['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50'],
            ['is_vat_registered' => true]
        );

        $html = $this->render($invoice);

        $this->assertStringContainsString('VAT %', $html);
        $this->assertStringContainsString('VAT amount', $html);
        $this->assertStringContainsString('Total incl. VAT', $html);
        $this->assertStringNotContainsString('ДДВ %', $html);
    }

    public function test_an_english_invoice_prints_the_iban_and_swift_when_they_are_filled(): void
    {
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $invoice->company->bankAccounts()->first()->update([
            'iban' => 'MK07300701104789126',
            'swift' => 'KOBSMK2X',
        ]);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringContainsString('IBAN', $html);
        $this->assertStringContainsString('MK07300701104789126', $html);
        $this->assertStringContainsString('SWIFT', $html);
        $this->assertStringContainsString('KOBSMK2X', $html);
    }

    public function test_an_english_invoice_falls_back_to_the_account_number_when_no_iban_is_set(): void
    {
        // Кај дел од клиентите IBAN-от веќе стои во полето „Сметка (IBAN)“.
        // Проверуваме дека бројот се појавува ВО IBAN-редот (не само во
        // постоечкиот „Account“ ред) — инаку тестот би минал и без fallback.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);

        $html = $this->render($invoice);

        $this->assertMatchesRegularExpression(
            '/pay-label">IBAN<\/td>\s*<td>300000000000123<\/td>/',
            $html
        );
    }

    public function test_an_english_invoice_prints_the_iban_when_the_account_number_is_empty(): void
    {
        // Токму сценариото поради кое IBAN/SWIFT-блокот мора да стои НАДВОР
        // од `@if ($mainAccount && $mainAccount->account_number)`: сметка со
        // IBAN, но без стар „account_number“, сепак мора да го испечати IBAN-от.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $invoice->company->bankAccounts()->first()->update([
            'account_number' => null,
            'iban' => 'MK07300701104789126',
        ]);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertMatchesRegularExpression(
            '/pay-label">IBAN<\/td>\s*<td>MK07300701104789126<\/td>/',
            $html
        );
    }

    public function test_an_english_invoice_omits_the_swift_row_when_it_is_empty(): void
    {
        // Празен ред на испечатена фактура изгледа како грешка.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);

        $html = $this->render($invoice);

        $this->assertStringNotContainsString('SWIFT', $html);
    }

    public function test_an_english_invoice_prints_both_countries(): void
    {
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $invoice->partner->update(['country' => 'Germany']);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringContainsString('Germany', $html);
        $this->assertStringContainsString('North Macedonia', $html);
    }

    public function test_a_denar_invoice_prints_neither_iban_swift_nor_country(): void
    {
        // Македонската фактура останува каква што беше.
        $invoice = $this->invoice();
        $invoice->company->bankAccounts()->first()->update(['iban' => 'MK07300701104789126', 'swift' => 'KOBSMK2X']);
        $invoice->partner->update(['country' => 'Германија']);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringNotContainsString('SWIFT', $html);
        $this->assertStringNotContainsString('KOBSMK2X', $html);
        $this->assertStringNotContainsString('Германија', $html);
        $this->assertStringNotContainsString('Северна Македонија', $html);
    }
}
