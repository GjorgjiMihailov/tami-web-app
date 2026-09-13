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

        $this->assertSame('mk', $invoice->language);
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

        $this->assertSame('en', $partner->fresh()->invoice_language);
        $this->assertSame('Germany', $partner->fresh()->country);
        $this->assertSame('mk', (new Partner)->invoice_language);
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
        $this->assertSame('mk', $invoice->fresh()->language);
        $this->assertFalse($invoice->fresh()->isForeignCurrency());
    }
}
