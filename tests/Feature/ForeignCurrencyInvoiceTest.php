<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignCurrencyInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    public function test_a_fresh_invoice_defaults_to_macedonian_denars_in_memory(): void
    {
        // Колона со default во базата НЕ полни свеж објект во меморија —
        // затоа стандардните вредности мора да стојат и во $attributes.
        $invoice = new SalesInvoice;

        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->language);
        $this->assertSame('MKD', $invoice->currency);
        $this->assertSame('1.000000', (string) $invoice->exchange_rate);
        $this->assertFalse($invoice->isForeignCurrency());
    }

    public function test_an_invoice_in_a_foreign_currency_reports_itself_as_foreign(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);

        $this->assertTrue($invoice->fresh()->isForeignCurrency());
        $this->assertSame('61.500000', (string) $invoice->fresh()->exchange_rate);
    }

    public function test_the_five_allowed_currencies_are_exactly_these(): void
    {
        $this->assertSame(['MKD', 'EUR', 'USD', 'GBP', 'CHF'], SalesInvoice::CURRENCIES);
    }

    public function test_a_partner_defaults_to_macedonian_and_can_store_a_country(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'invoice_language' => 'en',
            'country' => 'Germany',
        ]);

        $this->assertSame(\App\Support\InvoiceLanguage::EN, $partner->fresh()->invoice_language);
        $this->assertSame('Germany', $partner->fresh()->country);
        $this->assertSame(\App\Support\InvoiceLanguage::MK, (new Partner)->invoice_language);
    }

    public function test_a_bank_account_can_store_an_iban_and_a_swift(): void
    {
        $company = Company::factory()->create();
        $account = $company->bankAccounts()->create([
            'bank_name' => 'Komercijalna',
            'account_number' => '300000000000123',
            'iban' => 'MK07300701104789126',
            'swift' => 'KOBSMK2X',
            'position' => 0,
        ]);

        $this->assertSame('MK07300701104789126', $account->fresh()->iban);
        $this->assertSame('KOBSMK2X', $account->fresh()->swift);
    }

    public function test_existing_invoices_are_untouched_by_the_migration(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertSame('MKD', $invoice->fresh()->currency);
        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->fresh()->language);
        $this->assertFalse($invoice->fresh()->isForeignCurrency());
    }

    public function test_an_individual_profile_can_set_the_partner_invoice_language(): void
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Ltd']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editInvoiceLanguage', 'en')
            ->set('editCountry', 'Germany')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(\App\Support\InvoiceLanguage::EN, $partner->fresh()->invoice_language);
        $this->assertSame('Germany', $partner->fresh()->country);
    }

    public function test_a_legal_entity_never_sees_the_invoice_language_field(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->assertDontSee('Јазик на фактура');
    }

    public function test_a_legal_entity_cannot_force_an_english_partner_through_the_wire(): void
    {
        // Скриено поле во Blade не е заклучување. Серверот мора да одбие.
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editInvoiceLanguage', 'en')
            ->call('save');

        $this->assertSame(\App\Support\InvoiceLanguage::MK, $partner->fresh()->invoice_language);
    }

    public function test_the_country_field_is_available_to_a_legal_entity_too(): void
    {
        // Државата е обична адресна податока, не дел од девизната гранка.
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editCountry', 'Србија')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Србија', $partner->fresh()->country);
    }

    public function test_the_profile_stores_an_iban_and_a_swift_per_bank_account(): void
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts', [[
                'bank_name' => 'Komercijalna',
                'account_number' => '300000000000123',
                'iban' => 'MK07300701104789126',
                'swift' => 'KOBSMK2X',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $account = $company->fresh()->bankAccounts->first();
        $this->assertSame('MK07300701104789126', $account->iban);
        $this->assertSame('KOBSMK2X', $account->swift);
    }

    public function test_a_row_with_only_an_iban_is_still_kept(): void
    {
        // Празен ред се фрла, но ред со внесен IBAN не е празен.
        $company = Company::factory()->create(['type' => 'individual']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts', [[
                'bank_name' => '',
                'account_number' => '',
                'iban' => 'DE89370400440532013000',
                'swift' => '',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('DE89370400440532013000', $company->fresh()->bankAccounts->first()?->iban);
    }

    private function individualCompanyWithPartner(string $partnerLanguage = 'en'): array
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'Acme Ltd',
            'invoice_language' => $partnerLanguage,
        ]);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        return [$company, $partner, $admin];
    }

    private function draftLine(): array
    {
        return [[
            'item_id' => '',
            'description' => 'Consulting services',
            'quantity' => '1',
            'unit_price' => '500',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]];
    }

    public function test_an_individual_can_save_a_draft_in_euros(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame('61.500000', (string) $invoice->exchange_rate);
    }

    public function test_the_language_is_taken_from_the_partner_and_frozen_on_the_invoice(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('en');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save');

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(\App\Support\InvoiceLanguage::EN, $invoice->language);

        // Подоцнежна промена кај кооперантот не ја менува издадената фактура.
        $partner->update(['invoice_language' => 'mk']);
        $this->assertSame(\App\Support\InvoiceLanguage::EN, $invoice->fresh()->language);
    }

    public function test_a_foreign_currency_without_a_rate_is_refused(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasErrors('exchangeRate');

        $this->assertSame(0, SalesInvoice::where('company_id', $company->id)->count());
    }

    public function test_a_zero_or_negative_rate_is_refused(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        foreach (['0', '-1'] as $rate) {
            \Livewire\Livewire::actingAs($admin)
                ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
                ->set('partnerId', (string) $partner->id)
                ->set('invoiceDate', '2026-09-13')
                ->set('dueDate', '2026-09-30')
                ->set('currency', 'EUR')
                ->set('exchangeRate', $rate)
                ->set('lines', $this->draftLine())
                ->call('save')
                ->assertHasErrors('exchangeRate');
        }
    }

    public function test_a_legal_entity_is_forced_back_to_denars_even_if_euros_arrive_over_the_wire(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create(['invoice_language' => 'en']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('MKD', $invoice->currency);
        $this->assertSame('1.000000', (string) $invoice->exchange_rate);
        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->language);
    }

    public function test_a_legal_entity_never_sees_the_currency_field(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Валута');
    }

    public function test_switching_to_a_currency_offers_the_last_rate_used_for_it(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'currency' => 'EUR',
            'exchange_rate' => '61.480000',
            'invoice_date' => '2026-08-01',
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'EUR')
            ->assertSet('exchangeRate', '61.480000');
    }

    public function test_switching_back_to_denars_resets_the_rate_to_one(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('currency', 'MKD')
            ->assertSet('exchangeRate', '1');
    }

    public function test_the_nbrm_button_fills_the_rate_for_the_invoice_date(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        // Постоечкиот ExchangeRateService кешира во табелата exchange_rates и
        // повикува мрежа само кога нема кеш. Полниме кеш, па тестот не оди на
        // интернет.
        \App\Models\ExchangeRate::create([
            'rate_date' => '2026-09-13',
            'currency_code' => 'EUR',
            'rate' => '61.4955',
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-09-13')
            ->set('currency', 'EUR')
            ->call('fetchRate')
            ->assertHasNoErrors()
            ->assertSet('exchangeRate', '61.4955');
    }

    public function test_a_failing_nbrm_call_leaves_the_rate_typeable_instead_of_crashing(): void
    {
        // Паднат НБРМ не смее да ја сруши формата — фактурата мора да може да
        // се издаде со рачно впишан курс.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Illuminate\Support\Facades\Http::fake([
            'www.nbrm.mk/*' => \Illuminate\Support\Facades\Http::response('', 500),
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-09-13')
            ->set('currency', 'EUR')
            ->call('fetchRate')
            ->assertHasErrors('exchangeRate');
    }

    public function test_a_successful_retry_after_a_failed_nbrm_call_clears_the_old_error(): void
    {
        // Livewire ја памети грешката на exchangeRate меѓу барањата. Стар
        // неуспех од НБРМ не смее да остане прикажан откако следен обид
        // го запишал точниот курс.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Illuminate\Support\Facades\Http::fake([
            'www.nbrm.mk/*' => \Illuminate\Support\Facades\Http::response('', 500),
        ]);

        $test = \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-09-13')
            ->set('currency', 'EUR')
            ->call('fetchRate')
            ->assertHasErrors('exchangeRate');

        // НБРМ „закрепнува“ — во тестот тоа значи дека курсот е во кешот,
        // истиот пат по кој ExchangeRateService го проверува пред мрежата,
        // па тестот не оди на интернет ниту на вториот обид.
        \App\Models\ExchangeRate::create([
            'rate_date' => '2026-09-13',
            'currency_code' => 'EUR',
            'rate' => '61.4955',
        ]);

        $test->call('fetchRate')
            ->assertHasNoErrors('exchangeRate')
            ->assertSet('exchangeRate', '61.4955');
    }

    public function test_the_nbrm_button_does_nothing_for_denars(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'MKD')
            ->call('fetchRate')
            ->assertSet('exchangeRate', '1');
    }

    public function test_a_euro_invoice_is_posted_to_the_ledger_in_denars(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
            'invoice_date' => '2026-09-13',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $entry = $invoice->fresh()->journalEntry;
        $receivable = $entry->lines->firstWhere(fn ($line) => $line->account->code === '120');
        $revenue = $entry->lines->firstWhere(fn ($line) => $line->account->code === '740');

        // 500 EUR × 61,50 = 30.750 денари
        $this->assertSame(0, bccomp('30750.00', $receivable->debit, 2));
        $this->assertSame(0, bccomp('30750.00', $revenue->credit, 2));
    }

    public function test_a_euro_invoice_keeps_the_original_amount_on_the_ledger_line(): void
    {
        // journal_entry_lines веќе носи currency_code/exchange_rate/foreign_amount
        // и формата за рачно книжење веќе ги полни. Фактурата го користи истиот
        // образец — денарскиот износ не смее да го проголта оригиналот.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame('EUR', $receivable->currency_code);
        $this->assertSame(0, bccomp('61.500000', $receivable->exchange_rate, 6));
        $this->assertSame(0, bccomp('500.00', $receivable->foreign_amount, 2));
    }

    public function test_a_denar_invoice_leaves_the_currency_columns_at_their_defaults(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('mk');
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'MKD',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '100.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame('MKD', $receivable->currency_code);
        $this->assertNull($receivable->foreign_amount);
    }

    public function test_a_euro_invoice_ledger_entry_balances_to_the_last_denar(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.473300',
        ]);
        $invoice->lines()->create([
            'description' => 'Odd amount',
            'quantity' => '3',
            'unit_price' => '33.33',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $lines = $invoice->fresh()->journalEntry->lines;
        $debits = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 2), '0.00');
        $credits = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 2), '0.00');

        $this->assertSame(0, bccomp($debits, $credits, 2), "Дебит {$debits} наспроти кредит {$credits}");
    }

    public function test_a_denar_invoice_posts_exactly_the_same_numbers_as_before(): void
    {
        // Курсот е 1 и множењето не смее да го помести ниту еден износ.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('mk');
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'MKD',
            'exchange_rate' => '1.000000',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1234.56',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame(0, bccomp('1234.56', $receivable->debit, 2));
    }

    public function test_a_full_payment_on_a_euro_invoice_clears_the_receivable_to_zero(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        $service = app(\App\Services\Invoicing\SalesInvoiceService::class);
        $service->confirm($invoice->fresh(['lines', 'company']), $admin->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments', 'company']), '500.00', '2026-09-20', 'bank', $admin->id);

        // Плаќањето се чува во валутата на фактурата...
        $this->assertSame('500.00', $invoice->fresh(['lines', 'payments'])->paidTotal());
        $this->assertSame('0.00', $invoice->fresh(['lines', 'payments'])->balanceDue());

        // ...а во главната книга сметка 120 се затвора точно на нула.
        $receivableMovement = \App\Models\JournalEntryLine::whereHas('account', fn ($q) => $q->where('code', '120'))
            ->get()
            ->reduce(fn ($carry, $line) => bcsub(bcadd($carry, $line->debit, 2), $line->credit, 2), '0.00');

        $this->assertSame(0, bccomp($receivableMovement, '0', 2), "Остаток на 120: {$receivableMovement}");
    }

    public function test_a_foreign_currency_invoice_is_refused_by_the_ujp_send_endpoint(): void
    {
        // е-Фактура прима денари. Погрешно испратен износ е поскап од
        // заклучено копче.
        $company = Company::factory()->create([
            'type' => 'individual',
            'efaktura_credential_mode' => \App\Models\Company::EFAKTURA_MODE_OWN,
            // Комплетна адреса — инаку `hasCompleteAddress()` фаќа 422 порано
            // и тестот не би ја докажал валутната проверка воопшто.
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->postJson(route('sales-invoices.efaktura.signing-input', [$company, $invoice]), [
                'certificateBase64' => 'x',
            ])
            ->assertStatus(422);
    }

    public function test_a_foreign_currency_invoice_cannot_be_sent_either(): void
    {
        $company = Company::factory()->create([
            'type' => 'individual',
            'efaktura_credential_mode' => \App\Models\Company::EFAKTURA_MODE_OWN,
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'currency' => 'USD',
            'exchange_rate' => '56.200000',
        ]);
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->postJson(route('sales-invoices.efaktura.send', [$company, $invoice]), [
                'token' => 'whatever',
                'signature' => 'whatever',
            ])
            ->assertStatus(422);

        $this->assertSame('not_sent', $invoice->fresh()->efaktura_status);
    }
}
