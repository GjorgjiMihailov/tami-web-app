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

    /** Токенот е личен — на човекот што потпишува, не на фирмата. */
    private function giveToken(User $user): User
    {
        $user->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();

        return $user->fresh();
    }

    public function test_sign_and_send_button_hidden_without_a_personal_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_admin_with_a_personal_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_an_internal_client_with_an_own_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = $this->giveToken(User::factory()->create(['company_id' => $company->id]));
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_hidden_for_an_internal_client_without_a_personal_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    /** Клиент без запишан личен токен го гледа советот, но никогаш копчето. */
    public function test_client_without_a_token_sees_the_register_device_hint_but_no_button(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('немаш регистриран токен')
            ->assertSeeHtml(route('profile'))
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_freelancer_client_without_a_token_does_not_see_the_register_device_hint(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('немаш регистриран токен')
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_an_assigned_accountant_with_an_own_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $accountant = $this->giveToken(User::factory()->create());
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_already_sent_invoice_shows_sent_badge_not_button(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
            'efaktura_status' => 'sent', 'efaktura_sent_at' => now(),
        ]);
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Испратена до УЈП')
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    /** Гледачот има личен токен, но нема право да потпишува: копчето мора да е скриено. */
    public function test_sign_and_send_button_hidden_for_a_freelancer_client_even_with_an_own_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = $this->giveToken(User::factory()->create(['company_id' => $company->id]));
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }
}
