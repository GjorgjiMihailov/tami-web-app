<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\ProformaForm;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Services\Efaktura\EfakturaDocumentBuilder;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\VatMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LineDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    // ---- Пресметка ----

    public function test_zero_discount_gives_exactly_the_old_amounts(): void
    {
        $this->assertSame(VatMath::lineFromNet('3', '19.99', '18'), VatMath::lineFromNet('3', '19.99', '18', '0'));
        $this->assertSame(VatMath::lineFromGross('3', '23.59', '18'), VatMath::lineFromGross('3', '23.59', '18', '0'));
        $this->assertSame(VatMath::lineFromNet('3', '19.99', '18'), VatMath::lineFromNet('3', '19.99', '18', 'abc'));
    }

    public function test_a_percentage_discount_reduces_the_net_and_the_vat_follows(): void
    {
        // 2 × 500 = 1000, минус 10% = 900, ДДВ 18% = 162, вкупно 1062.
        $this->assertSame(['net' => '900.00', 'vat' => '162.00', 'gross' => '1062.00'], VatMath::lineFromNet('2', '500.00', '18', '10'));
    }

    public function test_the_discount_rounds_once_not_twice(): void
    {
        // 3 × 33.33 = 99.99; × 0.85 = 84.9915 → 84.99. (Две заокружувања би дале 84.99 или 85.00 по патот.)
        $this->assertSame('84.99', VatMath::lineFromNet('3', '33.33', '0', '15')['net']);
        // 7 × 0.15 = 1.05; × 0.5 = 0.525 → 0.53 (half up).
        $this->assertSame('0.53', VatMath::lineFromNet('7', '0.15', '0', '50')['net']);
    }

    public function test_a_discount_on_a_gross_entered_line_applies_to_the_gross_and_the_split_stays_exact(): void
    {
        // 2 × 118 = 236, минус 10% = 212.40 вкупно; основа 180.00, ДДВ 32.40.
        $amounts = VatMath::lineFromGross('2', '118.00', '18', '10');

        $this->assertSame('212.40', $amounts['gross']);
        $this->assertSame('180.00', $amounts['net']);
        $this->assertSame('32.40', $amounts['vat']);
    }

    public function test_the_line_model_exposes_original_discount_and_effective_prices(): void
    {
        $line = new SalesInvoiceLine(['quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18', 'discount_percent' => '10']);

        $this->assertTrue($line->hasDiscount());
        $this->assertSame('1000.00', $line->originalLineTotal());
        $this->assertSame('900.00', $line->lineTotal());
        $this->assertSame('100.00', $line->discountAmount());
        $this->assertSame('500.00', $line->originalUnitPrice());
        $this->assertSame('450.0000', $line->effectiveUnitPrice());
        $this->assertSame('1062.00', $line->grossTotal());

        $plain = new SalesInvoiceLine(['quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18']);
        $this->assertFalse($plain->hasDiscount());
        $this->assertSame('500.00', $plain->effectiveUnitPrice());
        $this->assertSame('500.00', $plain->originalUnitPrice());
    }

    // ---- е-Фактура ----

    public function test_efaktura_carries_the_discount_per_item_and_the_totals_stay_consistent(): void
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567', 'street_address' => 'Х', 'street_number' => '1', 'postal_code' => '1000', 'city' => 'Скопје']);
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4030007654321', 'street_address' => 'Y', 'street_number' => '2', 'postal_code' => '1000', 'city' => 'Скопје']);
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Со рабат', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00', 'discount_percent' => '10', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'Без рабат', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $items = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']))['document'];
        [$discounted, $plain] = $items['docItems'];

        $this->assertSame(500.0, $discounted['docItemUnitOriginalPriceWoVat']);
        $this->assertSame(50.0, $discounted['docItemUnitDiscountAmount']);
        $this->assertSame(450.0, $discounted['docItemUnitPriceWoVat']);
        $this->assertSame(1000.0, $discounted['docItemTotalOriginalPriceWoVat']);
        $this->assertSame(900.0, $discounted['docItemTotalPriceWoVat']);
        $this->assertSame(162.0, $discounted['docItemTotalVat']);
        $this->assertSame(1062.0, $discounted['docItemTotalPriceWVat']);

        // Ставка без рабат: оригиналната и нето цената се исти, рабат нула — како порано.
        $this->assertSame(100.0, $plain['docItemUnitOriginalPriceWoVat']);
        $this->assertEquals(0, $plain['docItemUnitDiscountAmount']);
        $this->assertSame(100.0, $plain['docItemTotalOriginalPriceWoVat']);

        // Документот се собира од износите по рабат.
        $this->assertSame(1000.0, $items['docTotals']['docNetAmount']);
        $this->assertSame(180.0, $items['docTotals']['docVatAmount']);
        $this->assertSame(1180.0, $items['docTotals']['docGrossAmount']);
    }

    // ---- Форма, потврда и книжење ----

    public function test_the_invoice_form_saves_the_discount_and_confirming_books_the_discounted_amounts(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $admin = $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'Со рабат')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '500')
            ->set('lines.0.discount_percent', '10')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::first();
        $this->assertSame('10.00', (string) $invoice->lines()->first()->discount_percent);
        $this->assertSame('1062.00', $invoice->grandTotal());

        app(SalesInvoiceService::class)->confirm($invoice, $admin->id);

        // Книжењето е со износот по рабат: потрошувачот е задолжен за 1062, а приходот е 900.
        $entry = $invoice->fresh()->journalEntry->load('lines');
        $this->assertEqualsWithDelta(1062.0, (float) $entry->lines->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(1062.0, (float) $entry->lines->sum('credit'), 0.001);
        $this->assertTrue($entry->lines->contains(fn ($line) => abs((float) $line->credit - 900.0) < 0.001), 'Приходот мора да е 900 (по рабат), не 1000.');
    }

    public function test_a_discount_over_one_hundred_or_negative_is_rejected(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        foreach (['101', '-5', 'abc'] as $bad) {
            Livewire::test(SalesInvoiceForm::class, ['company' => $company])
                ->set('partnerId', (string) $partner->id)
                ->set('lines.0.description', 'X')
                ->set('lines.0.discount_percent', $bad)
                ->call('save')
                ->assertHasErrors(['lines.0.discount_percent']);
        }
    }

    public function test_a_blank_discount_is_stored_as_zero(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'X')
            ->set('lines.0.discount_percent', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.00', (string) SalesInvoiceLine::first()->discount_percent);
    }

    public function test_the_invoice_pdf_shows_the_discount_column_only_when_there_is_a_discount(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $plain = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $plain->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $discounted = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 2]);
        $discounted->lines()->create(['description' => 'Б', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'discount_percent' => '10', 'vat_treatment' => 'standard']);

        $html = fn (SalesInvoice $invoice) => view('pdf.sales-invoice', ['invoice' => $invoice->fresh(['lines', 'company', 'partner', 'payments'])])->render();

        $this->assertStringNotContainsString('Рабат %', $html($plain));
        $this->assertStringContainsString('Рабат %', $html($discounted));
    }

    // ---- Профактура ----

    public function test_the_proforma_stores_the_discount_totals_follow_and_conversion_carries_it(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'Со рабат')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '500')
            ->set('lines.0.discount_percent', '10')
            ->call('save')
            ->assertHasNoErrors();

        $proforma = ProformaInvoice::first();
        $this->assertSame('1062.00', $proforma->grandTotal());
        $this->assertSame('10.00', (string) ProformaInvoiceLine::first()->discount_percent);

        Livewire::withQueryParams(['proforma' => $proforma->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('lines.0.discount_percent', '10.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1062.00', SalesInvoice::first()->grandTotal());
    }
}
