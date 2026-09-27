<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaReadinessScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    private function accountantFor(Company $company): User
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        return $accountant;
    }

    private function confirmedInvoice(Company $company): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => now()->toDateString(),
        ]);
        $invoice->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00', 'vat_treatment' => 'standard']);

        return $invoice;
    }

    // ---- Излезна фактура: зошто нема копче ----

    public function test_an_accountant_without_a_personal_token_is_told_to_register_one_and_gets_a_profile_link(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('немаш регистриран токен')
            ->assertSeeHtml(route('profile'))
            ->assertDontSee('Потпиши и испрати до УЈП');
    }
    public function test_the_personal_token_needs_both_the_token_and_the_eujp_id(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);

        $accountant->forceFill(['efaktura_token_serial_number' => '1A2B3C'])->save();
        Livewire::actingAs($accountant->fresh())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('немаш регистриран токен');

        $accountant->forceFill(['efaktura_eujp_id' => 'EUJP-9'])->save();
        Livewire::actingAs($accountant->fresh())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП')
            ->assertDontSee('немаш регистриран токен');
    }
    public function test_an_accountant_with_a_personal_token_shows_the_send_button_and_no_warning(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);
        $accountant->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();

        Livewire::actingAs($accountant->fresh())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП')
            ->assertDontSee('немаш регистриран токен');
    }

    // ---- Профил на фирма: токенот е личен, не на фирмата ----

    public function test_the_company_profile_points_to_the_personal_token_and_offers_no_device_registration(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company])
            ->assertSee('потпишува најавениот корисник со')
            ->assertSeeHtml(route('profile'))
            ->assertDontSee('Побарај користење на фирмениот сертификат')
            ->assertDontSee('токен на канцеларијата')
            ->assertDontSee('Потпишувачки уред')
            ->assertDontSee('Регистрирај токен');
    }
}
