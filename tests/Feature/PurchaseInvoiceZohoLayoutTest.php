<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoicePayment;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceZohoLayoutTest extends TestCase
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

    private function expenseAccount(Company $company): Account
    {
        return Account::where('company_id', $company->id)->where('code', '462')->first();
    }

    private function bill(Company $company, Partner $partner, array $extra = [], string $net = '1000.00'): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::factory()->create($extra + [
            'company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed',
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(),
        ]);
        PurchaseInvoiceLine::factory()->create(['purchase_invoice_id' => $invoice->id, 'quantity' => '1.000', 'unit_price' => $net, 'vat_rate' => '0.00']);

        return $invoice;
    }

    // ---- Рабат ----

    public function test_a_purchase_line_discount_reduces_the_net_and_the_vat_follows(): void
    {
        $line = new PurchaseInvoiceLine(['quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18', 'discount_percent' => '10']);

        $this->assertTrue($line->hasDiscount());
        $this->assertSame('1000.00', $line->originalLineTotal());
        $this->assertSame('900.00', $line->lineTotal());
        $this->assertSame('100.00', $line->discountAmount());
        $this->assertSame('162.00', $line->vatAmount());
        $this->assertSame('500.00', $line->originalUnitPrice());
        $this->assertSame('450.0000', $line->effectiveUnitPrice());

        $plain = new PurchaseInvoiceLine(['quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18']);
        $this->assertSame('1000.00', $plain->lineTotal());
        $this->assertSame('500.00', $plain->effectiveUnitPrice());
    }

    public function test_the_form_saves_the_discount_and_confirming_books_the_discounted_amount(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $account = $this->expenseAccount($company);
        $admin = $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'ДОБ-1')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Услуга')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '500')
            ->set('lines.0.discount_percent', '10')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = PurchaseInvoice::first();
        $this->assertSame('10.00', (string) $invoice->lines()->first()->discount_percent);
        $this->assertSame('1062.00', $invoice->grandTotal());

        app(PurchaseInvoiceService::class)->confirm($invoice, $admin->id);

        $entry = $invoice->fresh()->journalEntry->load('lines');
        $this->assertEqualsWithDelta(1062.0, (float) $entry->lines->sum('credit'), 0.001);   // обврска кон добавувачот
        $this->assertEqualsWithDelta(1062.0, (float) $entry->lines->sum('debit'), 0.001);
        $this->assertTrue($entry->lines->contains(fn ($line) => abs((float) $line->debit - 900.0) < 0.001), 'Трошокот мора да е 900 (по рабат), не 1000.');
        $this->assertTrue($entry->lines->contains(fn ($line) => abs((float) $line->debit - 162.0) < 0.001), 'Претходниот ДДВ мора да е 162.');
    }

    public function test_stock_is_received_at_the_discounted_unit_cost(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $product = Item::factory()->for($company)->create(['type' => 'product']);
        $admin = $this->admin();

        $invoice = PurchaseInvoice::factory()->create([
            'company_id' => $company->id, 'partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'item_id' => $product->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '100.00',
            'vat_rate' => '0.00', 'discount_percent' => '20', 'vat_deductible' => true,
        ]);

        app(PurchaseInvoiceService::class)->confirm($invoice, $admin->id);

        // 10 × 100 = 1000, минус 20% = 800 → 80 по единица во залиха.
        $level = StockLevel::where('item_id', $product->id)->first();
        $this->assertEqualsWithDelta(80.0, (float) $level->average_cost, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $level->quantity_on_hand, 0.0001);
    }

    public function test_a_bad_discount_is_rejected_and_a_blank_one_is_zero(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = $this->expenseAccount($company);
        $this->admin();

        foreach (['101', '-1', 'abc'] as $bad) {
            Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
                ->set('partnerId', (string) $partner->id)
                ->set('supplierInvoiceNumber', 'X-'.$bad)
                ->set('lines.0.account_id', (string) $account->id)
                ->set('lines.0.description', 'X')
                ->set('lines.0.discount_percent', $bad)
                ->call('save')
                ->assertHasErrors(['lines.0.discount_percent']);
        }

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'ПРАЗНО')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'X')
            ->set('lines.0.discount_percent', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.00', (string) PurchaseInvoiceLine::first()->discount_percent);
    }

    // ---- Форма ----

    public function test_payment_terms_set_the_due_date_and_the_vendors_terms_fill_the_select(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['payment_terms_days' => 30]);
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-03-01')
            ->set('paymentTermsDays', '15')
            ->assertSet('dueDate', '2026-03-16')
            ->set('partnerId', (string) $partner->id)
            ->assertSet('paymentTermsDays', '30')
            ->assertSet('dueDate', '2026-03-31')
            ->set('invoiceDate', '2026-04-01')
            ->assertSet('dueDate', '2026-05-01');
    }

    public function test_order_number_and_the_vendor_block_are_saved_and_shown(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['address' => 'Партизанска 9, Скопје', 'email' => 'dobavuvac@primer.mk']);
        $this->bill($company, $partner, [], '750.00');   // му должиме 750
        $account = $this->expenseAccount($company);
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Му должиме')
            ->set('partnerId', (string) $partner->id)
            ->assertSee('Партизанска 9, Скопје')
            ->assertSee('dobavuvac@primer.mk')
            ->assertSee('Му должиме')
            ->assertSee('750,00 ден')
            ->set('supplierInvoiceNumber', 'НОВА-1')
            ->set('orderNumber', 'НАР-77')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('НАР-77', PurchaseInvoice::where('supplier_invoice_number', 'НОВА-1')->first()->order_number);
    }

    public function test_the_form_offers_save_as_draft_and_save_and_confirm(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSee('Зачувај како нацрт')
            ->assertSee('Зачувај и потврди')
            ->assertSee('не се печати')
            ->assertDontSee('Save as Open');
    }

    public function test_save_and_confirm_saves_and_books_the_bill(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = $this->expenseAccount($company);
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'ПОТВРДИ-1')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Услуга')
            ->set('lines.0.unit_price', '100')
            ->call('saveAndConfirm')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invoice = PurchaseInvoice::first();
        $this->assertSame('confirmed', $invoice->status);
        $this->assertNotNull($invoice->journal_entry_id);
    }

    public function test_save_as_draft_never_confirms(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = $this->expenseAccount($company);
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'НАЦРТ-1')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('draft', PurchaseInvoice::first()->status);
        $this->assertNull(PurchaseInvoice::first()->journal_entry_id);
    }

    public function test_when_confirming_fails_the_saved_draft_is_kept_and_the_error_shown(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $product = Item::factory()->for($company)->create(['type' => 'product']);
        $this->admin();

        // Стока со ДДВ без право на одбивка: книжењето тоа не го поддржува и фрла грешка.
        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'ГРЕШКА-1')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $product->id)
            ->set('lines.0.vat_deductible', false)
            ->call('saveAndConfirm')
            ->assertHasErrors(['confirm'])
            ->assertNoRedirect()
            ->assertSee('Фактурата е зачувана како нацрт');

        $invoice = PurchaseInvoice::first();
        $this->assertNotNull($invoice, 'The draft must not be lost when confirmation fails.');
        $this->assertSame('draft', $invoice->status);
    }

    public function test_an_invalid_bill_is_not_saved_by_save_and_confirm(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('saveAndConfirm')
            ->assertHasErrors(['partnerId', 'supplierInvoiceNumber']);

        $this->assertSame(0, PurchaseInvoice::count());
    }

    // ---- Список ----

    public function test_a_company_with_no_bills_and_no_pending_documents_sees_the_empty_state(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Должите пари? Добро е да се плати навреме!')
            ->assertSeeHtml(route('purchase-invoices.create', $company))
            ->assertDontSee('<table', false);
    }

    public function test_the_table_shows_order_number_due_date_balance_and_days_overdue(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач Т']);
        $overdue = $this->bill($company, $partner, [
            'supplier_invoice_number' => 'ДОСПЕАНА-1', 'order_number' => 'НАР-5',
            'invoice_date' => now()->subDays(13)->toDateString(), 'due_date' => now()->subDays(3)->toDateString(),
        ], '1000.00');
        PurchaseInvoicePayment::factory()->create(['purchase_invoice_id' => $overdue->id, 'amount' => '400.00']);
        $this->admin();

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('ДОСПЕАНА-1')
            ->assertSee('НАР-5')
            ->assertSee('Доспеана пред 3 дена')
            ->assertSee('1.000,00 ден')
            ->assertSee('600,00 ден')       // за плаќање: 1000 − 400
            ->assertSeeHtml(route('purchase-invoices.show', [$company, $overdue]));
    }

    public function test_days_overdue_is_singular_for_one_day_and_zero_when_not_overdue(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $one = $this->bill($company, $partner, ['due_date' => now()->subDay()->toDateString()]);
        $future = $this->bill($company, $partner, ['due_date' => now()->addDays(5)->toDateString()]);
        $this->admin();

        $this->assertSame(1, $one->fresh(['lines', 'payments'])->daysOverdue());
        $this->assertSame(0, $future->fresh(['lines', 'payments'])->daysOverdue());

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])->assertSee('Доспеана пред 1 ден');
    }

    public function test_the_payment_filters_split_paid_unpaid_and_overdue(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $paid = $this->bill($company, $partner, ['supplier_invoice_number' => 'ПЛАТЕНА-1'], '100.00');
        PurchaseInvoicePayment::factory()->create(['purchase_invoice_id' => $paid->id, 'amount' => '100.00']);
        $this->bill($company, $partner, ['supplier_invoice_number' => 'ЧЕКА-2'], '100.00');
        $this->bill($company, $partner, ['supplier_invoice_number' => 'ДОСПЕАНА-3', 'due_date' => now()->subDay()->toDateString()], '100.00');
        $this->admin();

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'paid')
            ->assertSee('ПЛАТЕНА-1')->assertDontSee('ЧЕКА-2')->assertDontSee('ДОСПЕАНА-3')
            ->set('statusFilter', 'unpaid')
            ->assertSee('ЧЕКА-2')->assertSee('ДОСПЕАНА-3')->assertDontSee('ПЛАТЕНА-1')
            ->set('statusFilter', 'overdue')
            ->assertSee('ДОСПЕАНА-3')->assertDontSee('ЧЕКА-2')->assertDontSee('ПЛАТЕНА-1');
    }

    // ---- Детали ----

    public function test_the_detail_page_lists_the_working_years_bills_and_tells_you_what_is_next(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач Лево']);
        $other = Partner::factory()->for($company)->create(['name' => 'Друг Добавувач']);
        $open = $this->bill($company, $partner, ['supplier_invoice_number' => 'ОТВОРЕНА-1', 'order_number' => 'НАР-9', 'due_date' => now()->subDays(2)->toDateString()]);
        $sibling = $this->bill($company, $other, ['supplier_invoice_number' => 'СОСЕДНА-2']);
        $old = $this->bill($company, $other, ['supplier_invoice_number' => 'СТАРА-2019', 'invoice_date' => '2019-05-05', 'due_date' => '2019-06-05']);
        $this->admin();

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $open])
            ->assertSee('Сите влезни фактури')
            ->assertSee('СОСЕДНА-2')
            ->assertSeeHtml(route('purchase-invoices.show', [$company, $sibling]))
            ->assertDontSee('СТАРА-2019')
            ->assertSee('Што следува?')
            ->assertSee('доспеана пред 2 дена')
            ->assertSee('НАР-9');

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $old])
            ->assertSee('СТАРА-2019');
    }

    public function test_a_draft_bill_tells_you_to_confirm_it_and_a_foreign_one_is_404(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $draft = PurchaseInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => Partner::factory()->for($company), 'status' => 'draft']);
        $foreign = PurchaseInvoice::factory()->create(['company_id' => $other->id, 'partner_id' => Partner::factory()->for($other)]);
        $this->admin();

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $draft])
            ->assertSee('Прегледај ја фактурата и потврди ја');

        $this->get(route('purchase-invoices.show', [$company, $foreign]))->assertNotFound();
    }

    public function test_the_detail_table_shows_the_discount_column_only_when_there_is_one(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $plain = $this->bill($company, $partner);
        $discounted = $this->bill($company, $partner);
        $discounted->lines()->update(['discount_percent' => '15']);
        $this->admin();

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $plain])->assertDontSee('Рабат %');
        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $discounted])->assertSee('Рабат %');
    }
}
