<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceZohoLayoutTest extends TestCase
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

    // ---- Форма ----

    public function test_choosing_a_payment_term_sets_the_due_date_and_it_follows_the_invoice_date(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-03-01')
            ->set('paymentTermsDays', '30')
            ->assertSet('dueDate', '2026-03-31')
            ->set('invoiceDate', '2026-04-01')
            ->assertSet('dueDate', '2026-05-01')
            ->set('paymentTermsDays', '')          // „Рачно" — датата си остава
            ->set('dueDate', '2026-06-15')
            ->set('invoiceDate', '2026-04-05')
            ->assertSet('dueDate', '2026-06-15');
    }

    public function test_the_partners_payment_terms_fill_the_terms_select(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['payment_terms_days' => 45]);
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-03-01')
            ->set('partnerId', (string) $partner->id)
            ->assertSet('paymentTermsDays', '45')
            ->assertSet('dueDate', '2026-04-15');
    }

    public function test_order_number_and_terms_are_saved(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('orderNumber', 'НАР-55')
            ->set('terms', 'Плаќање во рок од 8 дена.')
            ->set('notes', 'Ви благодариме.')
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::first();
        $this->assertSame('НАР-55', $invoice->order_number);
        $this->assertSame('Плаќање во рок од 8 дена.', $invoice->terms);
        $this->assertSame('draft', $invoice->status);
    }

    public function test_the_form_shows_the_customer_address_and_outstanding_amount(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['address' => 'Илинденска 5, Скопје', 'email' => 'kupuvac@primer.mk']);
        $old = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-10']);
        $old->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'standard']);
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Адреса за фактурирање')
            ->set('partnerId', (string) $partner->id)
            ->assertSee('Илинденска 5, Скопје')
            ->assertSee('kupuvac@primer.mk')
            ->assertSee('Ненаплатено')
            ->assertSee('1.000,00 ден')
            ->assertSee('доспеано');
    }

    public function test_the_form_offers_save_as_draft_and_save_and_confirm(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSee('Зачувај како нацрт')
            ->assertSee('Зачувај и потврди')
            ->assertDontSee('Save and Send');
    }

    public function test_save_and_confirm_saves_numbers_and_books_the_invoice(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'Услуга')
            ->set('lines.0.unit_price', '100')
            ->call('saveAndConfirm')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invoice = SalesInvoice::first();
        $this->assertSame('confirmed', $invoice->status);
        $this->assertNotNull($invoice->formattedNumber());
        $this->assertNotNull($invoice->journal_entry_id);
    }

    public function test_save_as_draft_never_confirms(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('draft', SalesInvoice::first()->status);
        $this->assertNull(SalesInvoice::first()->journal_entry_id);
    }

    public function test_save_and_confirm_keeps_a_saved_draft_and_shows_the_error_when_confirming_fails(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $product = Item::factory()->for($company)->create(['type' => 'product']);   // нема залиха
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $product->id)
            ->set('lines.0.quantity', '5')
            ->call('saveAndConfirm')
            ->assertHasErrors(['confirm'])
            ->assertNoRedirect()
            ->assertSee('Фактурата е зачувана како нацрт');

        $invoice = SalesInvoice::first();
        $this->assertNotNull($invoice, 'The draft must not be lost when confirmation fails.');
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(1, $invoice->lines()->count());
    }

    public function test_save_and_confirm_does_not_confirm_an_invalid_invoice(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('saveAndConfirm')
            ->assertHasErrors(['partnerId']);

        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_converting_a_proforma_carries_the_number_terms_and_payment_term(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create([
            'company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed',
            'proforma_number_formatted' => 'ПФ-2026/12', 'terms' => 'Услови од профактурата', 'payment_terms_days' => 15,
        ]);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id]);
        $this->admin();

        Livewire::withQueryParams(['proforma' => $proforma->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('orderNumber', 'ПФ-2026/12')
            ->assertSet('terms', 'Услови од профактурата')
            ->assertSet('paymentTermsDays', '15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ПФ-2026/12', SalesInvoice::first()->order_number);
    }

    // ---- Детали ----

    public function test_the_detail_page_lists_the_working_years_invoices_on_the_left_and_highlights_the_open_one(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач Лево']);
        $other = Partner::factory()->for($company)->create(['name' => 'Друг Купувач']);
        $open = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => now()->toDateString()]);
        $sibling = SalesInvoice::factory()->for($company)->create(['partner_id' => $other->id, 'invoice_date' => now()->toDateString()]);
        $old = SalesInvoice::factory()->for($company)->create(['partner_id' => $other->id, 'invoice_date' => '2019-05-05', 'invoice_number_formatted' => 'СТАРА-2019']);
        $this->admin();

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $open])
            ->assertSee('Друг Купувач')
            ->assertSee('Сите фактури')
            ->assertSeeHtml(route('sales-invoices.show', [$company, $sibling]))
            ->assertDontSee('СТАРА-2019');   // од друга година — не е во листата

        // Отворена фактура од друга година сепак се појавува во листата.
        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $old])
            ->assertSee('СТАРА-2019');
    }

    public function test_the_detail_page_shows_the_linked_proforma_notes_terms_and_what_is_next(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => now()->toDateString(),
            'notes' => 'Ви благодариме', 'terms' => 'Услови на работење',
        ]);
        $invoice->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00', 'vat_treatment' => 'standard']);
        $proforma = ProformaInvoice::factory()->create([
            'company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'converted',
            'sales_invoice_id' => $invoice->id, 'proforma_number_formatted' => 'ПФ-2026/3',
        ]);
        $this->admin();

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('ПФ-2026/3')
            ->assertSeeHtml(route('proformas.index', [$company, 'proforma' => $proforma->id]))
            ->assertSee('Ви благодариме')
            ->assertSee('Услови на работење')
            ->assertSee('Што следува?')
            ->assertSee('Евидентирај ја уплатата');

        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $invoice->id, 'amount' => '100.00']);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Евидентирај ја уплатата');
    }

    public function test_a_draft_invoice_tells_you_to_confirm_it(): void
    {
        $company = Company::factory()->create();
        $draft = SalesInvoice::factory()->for($company)->create(['status' => 'draft']);
        $this->admin();

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $draft])
            ->assertSee('Прегледај ја фактурата и потврди ја');
    }

    public function test_an_invoice_of_another_company_is_still_404_on_the_detail_page(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $foreign = SalesInvoice::factory()->for($other)->create();
        $this->admin();

        $this->get(route('sales-invoices.show', [$company, $foreign]))->assertNotFound();
    }
}
