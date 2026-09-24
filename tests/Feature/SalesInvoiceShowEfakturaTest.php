<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowEfakturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        Role::findOrCreate('freelancer_client');
    }

    public function test_sign_and_send_button_hidden_without_registered_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_admin_with_registered_device(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_an_internal_client_with_an_own_token(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_hidden_for_an_internal_client_of_a_firm_mode_company(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_hidden_for_an_own_mode_client_without_a_token(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    /** Клиент во свој режим без запишан токен го гледа советот, но никогаш копчето. */
    public function test_own_mode_client_without_a_token_sees_the_register_device_hint_but_no_button(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Регистрирај потпишувачки уред за оваа компанија')
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_firm_mode_client_does_not_see_the_register_device_hint(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Регистрирај потпишувачки уред за оваа компанија');
    }

    public function test_freelancer_client_without_a_token_does_not_see_the_register_device_hint(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Регистрирај потпишувачки уред за оваа компанија');
    }

    public function test_sign_and_send_button_visible_for_an_assigned_accountant_with_an_own_token(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_already_sent_invoice_shows_sent_badge_not_button(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
            'efaktura_status' => 'sent', 'efaktura_sent_at' => now(),
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Испратена до УЈП')
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    /** Свој режим + ЕУЈП-ид + токен, но гледачот нема право да потпишува: копчето мора да е скриено. */
    public function test_sign_and_send_button_hidden_for_a_freelancer_client_even_with_an_own_token(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }
}
