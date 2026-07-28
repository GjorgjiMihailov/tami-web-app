<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_downloads_a_pdf_for_a_confirmed_invoice(): void
    {
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL']);
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $partner = Partner::factory()->for($company)->create(['name' => 'Customer DOO']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 1,
            'journal_entry_id' => $entry->id,
        ]);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$company, $invoice]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_a_draft_invoice_cannot_be_downloaded_as_pdf(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.pdf', [$company, $invoice]))
            ->assertStatus(403);
    }

    public function test_it_renders_the_logo_on_the_left_by_default(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', 'fake-png-bytes');

        $company = Company::factory()->create([
            'logo_path' => 'logos/1/logo.png',
            'logo_position' => 'left',
            'registration_number' => '7654321',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $expectedPath = Storage::disk('public')->path('logos/1/logo.png');
        $this->assertStringContainsString($expectedPath, $html);
        $this->assertStringNotContainsString('logo-row', $html);
        $this->assertSame(1, substr_count($html, '<img'));

        // The logo cell is emitted BEFORE the invoice badge cell for left-positioned logos.
        $imgPos = strpos($html, '<img');
        $badgePos = strpos($html, '<span class="badge">');
        $this->assertNotFalse($imgPos);
        $this->assertNotFalse($badgePos);
        $this->assertLessThan($badgePos, $imgPos);
    }

    public function test_it_renders_the_logo_on_the_right_when_configured(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', 'fake-png-bytes');

        $company = Company::factory()->create(['logo_path' => 'logos/1/logo.png', 'logo_position' => 'right']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertSame(1, substr_count($html, '<img'));

        // The badge cell is emitted BEFORE the logo cell for right-positioned logos —
        // the columns are swapped in the markup, not via a CSS transform.
        $imgPos = strpos($html, '<img');
        $badgePos = strpos($html, '<span class="badge">');
        $this->assertNotFalse($imgPos);
        $this->assertNotFalse($badgePos);
        $this->assertGreaterThan($badgePos, $imgPos);
    }

    public function test_it_renders_the_logo_centered_when_configured(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', 'fake-png-bytes');

        $company = Company::factory()->create(['logo_path' => 'logos/1/logo.png', 'logo_position' => 'center']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('logo-row', $html);
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function test_it_renders_no_image_tag_when_the_logo_file_is_missing_from_storage(): void
    {
        Storage::fake('public');
        // logo_path is set on the company but the file was never written to the disk.
        $company = Company::factory()->create(['logo_path' => 'logos/1/gone.png', 'logo_position' => 'left']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('logos/1/gone.png', $html);
    }

    public function test_it_renders_no_image_tag_when_company_has_no_logo(): void
    {
        $company = Company::factory()->create(['logo_path' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_it_shows_compact_issuer_and_recipient_blocks(): void
    {
        $company = Company::factory()->create([
            'name' => 'Fajnens Badi DOOEL',
            'address' => 'ul. Primer 1, Skopje',
            'tax_id' => 'MK4032000000000',
            'registration_number' => '7654321',
            'phone' => '070123456',
            'email' => 'info@primer.mk',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'ABV Trgovija DOO',
            'address' => 'bul. Ilinden 5, Bitola',
            'tax_id' => 'MK4021000000000',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Fajnens Badi DOOEL', $html);
        $this->assertStringContainsString('ul. Primer 1, Skopje', $html);
        $this->assertStringContainsString('ЕДБ: MK4032000000000', $html);
        $this->assertStringContainsString('ЕМБС: 7654321', $html);
        $this->assertStringContainsString('070123456', $html);
        $this->assertStringContainsString('info@primer.mk', $html);
        $this->assertStringContainsString('ABV Trgovija DOO', $html);
        $this->assertStringContainsString('bul. Ilinden 5, Bitola', $html);
        $this->assertStringContainsString('ЕДБ: MK4021000000000', $html);
    }

    public function test_it_omits_embs_segment_when_registration_number_is_blank(): void
    {
        $company = Company::factory()->create(['registration_number' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('ЕМБС', $html);
    }

    public function test_it_numbers_rows_and_shows_gross_total_per_line(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'First item', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Second item', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Р.б.', $html);
        $this->assertStringContainsString('Вкупно со ДДВ', $html);
        // First line: 2 * 500.00 = 1000.00 base + 18% VAT (180.00) = 1180.00 gross
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html);
        // Second line: 1000.00 base + 18% VAT (180.00) = 1180.00 gross
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html);
    }

    public function test_it_shows_vat_treatment_label_for_non_standard_lines(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create([
            'description' => 'Export sale', 'quantity' => '1', 'unit_price' => '1000.00',
            'vat_rate' => '0.00', 'vat_treatment' => 'export',
        ]);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('(Извоз)', $html);
    }

    public function test_it_hides_the_vat_column_for_a_non_vat_registered_company(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('ДДВ %', $html);
        // Scoped to the items-table header so the totals-box "Вкупно" cell can't satisfy it.
        $this->assertStringContainsString('<th style="width: 100px;">Вкупно</th>', $html);
        $this->assertStringNotContainsString('Вкупно со ДДВ', $html);
    }

    public function test_it_lists_all_company_bank_accounts_in_the_payment_box(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07210987654321098', 'position' => 1]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Начин на плаќање', $html);
        $this->assertStringContainsString('Комерцијална банка', $html);
        $this->assertStringContainsString('MK07300701104789126', $html);
        $this->assertStringContainsString('НЛБ Банка', $html);
        $this->assertStringContainsString('MK07210987654321098', $html);
    }

    public function test_it_shows_all_four_totals_rows(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        // Scoped to the totals-table label cells: a bare 'ДДВ'/'Вкупно' substring would
        // also be satisfied by the items-table header, so these must be real <td> labels.
        $this->assertStringContainsString('>Основа</td>', $html);
        $this->assertStringContainsString('>ДДВ</td>', $html);
        $this->assertStringContainsString('>Вкупно</td>', $html);
        $this->assertStringContainsString('>За доплата</td>', $html);
        $this->assertStringContainsString(\App\Support\Format::money('1000.00'), $html); // subtotal
        $this->assertStringContainsString(\App\Support\Format::money('180.00'), $html); // vat total
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html); // grand total / balance due
    }

    public function test_pdf_controller_eager_loads_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $partner = Partner::factory()->for($company)->create();
        $entry = \App\Models\JournalEntry::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed',
            'fiscal_year' => 2026, 'invoice_number' => 2, 'journal_entry_id' => $entry->id,
        ]);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$company, $invoice]));

        $response->assertOk();
    }

    public function test_it_shows_no_footnotes_when_vat_registered_and_no_custom_note(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true, 'invoice_footer_note' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('footnotes', $html);
    }

    public function test_it_shows_the_legal_note_for_a_non_vat_registered_company(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false, 'invoice_footer_note' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Фирмава не е ДДВ обврзник.', $html);
    }

    public function test_it_shows_the_custom_footer_note(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true, 'invoice_footer_note' => 'Стоката не подлежи на рекламација.']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Стоката не подлежи на рекламација.', $html);
        $this->assertStringNotContainsString('Фирмава не е ДДВ обврзник.', $html);
    }

    public function test_it_shows_the_legal_note_before_the_custom_footer_note_when_both_apply(): void
    {
        $company = Company::factory()->create([
            'is_vat_registered' => false,
            'invoice_footer_note' => 'Стоката не подлежи на рекламација.',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $legalPos = strpos($html, 'Фирмава не е ДДВ обврзник.');
        $customPos = strpos($html, 'Стоката не подлежи на рекламација.');
        $this->assertNotFalse($legalPos);
        $this->assertNotFalse($customPos);
        $this->assertLessThan($customPos, $legalPos);
    }
}
