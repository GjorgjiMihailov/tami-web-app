<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function seedAccounts(Company $company): void
    {
        foreach (['120', '740', '230', '660', '701', '100', '102'] as $code) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $code]
            );
        }
    }

    public function test_confirming_a_draft_invoice_from_the_show_screen(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_confirming_with_insufficient_stock_shows_an_error(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '5', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasErrors(['confirm']);

        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_recording_a_payment_and_cancel_button_is_hidden_once_paid(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->set('paymentAmount', '100.00')
            ->set('paymentDate', '2026-03-10')
            ->set('paymentMethod', 'bank')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertDontSee('Cancel invoice');

        $this->assertDatabaseHas('sales_invoice_payments', ['sales_invoice_id' => $invoice->id, 'amount' => '100.00']);
    }

    public function test_a_payment_amount_with_three_decimals_is_rejected(): void
    {
        // Реценизијата на гранката foreign-currency-invoice најде дека
        // 'numeric|min:0.01' не отфрла „500.005" — таквата вредност потоа
        // тргнува низ SalesInvoiceService::recordPayment(), каде bcadd со
        // scale 2 отсекува наместо заокружува, а decimal(15,2) колоната за
        // плаќањето заокружува. Двете не се согласуваат и остава остаток на
        // сметка 120 засекогаш. Валидацијата мора да ја запре вредноста тука.
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->set('paymentAmount', '50.005')
            ->set('paymentDate', '2026-03-10')
            ->set('paymentMethod', 'bank')
            ->call('recordPayment')
            ->assertHasErrors(['paymentAmount' => 'decimal']);

        $this->assertDatabaseMissing('sales_invoice_payments', ['sales_invoice_id' => $invoice->id]);
    }

    public function test_the_line_items_table_gets_the_hover_treatment_but_the_payments_table_does_not(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // sales_invoice_payments.created_by is a required (non-nullable) foreign key to users —
        // create the payment AFTER $admin exists, and pass created_by explicitly.
        $invoice->payments()->create(['amount' => '50.00', 'payment_date' => '2026-03-10', 'payment_method' => 'bank', 'created_by' => $admin->id]);
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])->html();

        // Two tables on this page have a <thead> now: the invoice's own line-items
        // table, and the document-manager component embedded at the bottom of the
        // page (also styled with the pattern, as of a later plan). Both legitimately
        // carry bg-gray-50. hover:bg-orange-50 should still be exactly 1, though —
        // the payments table below never gets it, and document-manager's table is
        // empty here (no documents seeded), so its own data-row hover never renders.
        $this->assertSame(2, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }

    public function test_mark_sent_sets_sent_at(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('markSent');

        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_mark_sent_rejects_draft_invoices(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('markSent')
            ->assertHasErrors(['markSent']);

        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_client_cannot_view_another_companys_invoice(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceShow::class, ['company' => $otherCompany, 'salesInvoice' => $invoice])
            ->assertForbidden();
    }

    public function test_a_euro_invoice_shows_eur_not_denari_on_its_own_screen(): void
    {
        // Ревизијата на гранката најде дека екранот секогаш печатеше „ден"
        // дури и на девизна фактура (Format::money default) — сметководителот
        // не може со ова да го порамни изводот од банка. Курсот исто така
        // мора да е видлив тука, не само во нацрт-формата за уредување.
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-03-01',
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 1,
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])->html();

        // „ден" сеуште легитимно се појавува во редот со курсот (61,50 ден за
        // 1 EUR) — тоа е конверзија ВО денари и е точно. Она што не смее да
        // се појави е вкупниот износ означен како денари.
        $this->assertStringNotContainsString('1.000,00 ден', $html);
        $this->assertStringContainsString('1.000,00 EUR', $html);
        $this->assertStringContainsString('Курс: 1 EUR = 61,50 ден', $html);
    }

    public function test_a_denar_invoice_still_shows_denari_and_no_exchange_rate_line(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])->html();

        $this->assertStringContainsString('100,00 ден', $html);
        $this->assertStringNotContainsString('Курс:', $html);
    }

    public function test_the_ujp_button_is_hidden_for_a_foreign_currency_invoice(): void
    {
        // Серверот и онака одбива со 422 (EfakturaSendController), но само
        // откако корисникот веќе го приклучил токенот. Копчето треба воопшто
        // да не се прикаже за девизна фактура.
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'eujp-1',
            'efaktura_token_serial_number' => 'serial-1',
        ]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-03-01',
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 1,
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])->html();

        $this->assertStringNotContainsString('Потпиши и испрати до УЈП', $html);
        $this->assertStringContainsString('е-Фактура прима само фактури во денари', $html);
    }

    public function test_an_invoice_from_another_year_is_flagged_but_still_opens(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertOk()
            ->assertSee('Запис од 2024');
    }
}
