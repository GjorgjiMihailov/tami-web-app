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

    public function test_a_firm_mode_company_is_told_that_office_token_mode_cannot_send_and_gets_a_profile_link(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('недостасува подготовка')
            ->assertSee('токен на канцеларијата')
            ->assertSeeHtml(route('companies.profile', $company))
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_an_own_mode_company_lists_exactly_what_is_missing(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => null,
            'efaktura_token_serial_number' => null,
        ]);
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Не е внесен X-EUJP-ID')
            ->assertSee('Не е регистриран потпишувачки уред')
            ->assertDontSee('токен на канцеларијата');

        $company->update(['efaktura_eujp_id' => 'EUJP-1']);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company->fresh(), 'salesInvoice' => $invoice])
            ->assertDontSee('Не е внесен X-EUJP-ID')
            ->assertSee('Не е регистриран потпишувачки уред');
    }

    public function test_a_fully_prepared_own_mode_company_shows_the_send_button_and_no_warning(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $invoice = $this->confirmedInvoice($company);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП')
            ->assertDontSee('недостасува подготовка');
    }

    // ---- Профил на фирма: сертификат ----

    public function test_an_accountant_sees_the_register_button_first_and_update_certificate_after_registering(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantFor($company);

        $component = Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company]);
        $component->assertSee('Регистрирај токен')->assertDontSee('Ажурирај сертификат');

        $component->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', now()->addYear()->toIso8601String());

        Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company->fresh()])
            ->assertSee('Ажурирај сертификат')
            ->assertSee('замени го регистрираниот уред')
            ->assertDontSee('Регистрирај токен');
    }

    public function test_the_profile_shows_the_readiness_checklist(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company])
            ->assertSee('Режим: сопствени акредитиви')
            ->assertSee('токенот на канцеларијата уште не е поддржан')
            ->assertSee('X-EUJP-ID')
            ->assertSee('Потпишувачки уред (сертификат)');
    }

    public function test_an_expired_certificate_is_flagged_and_one_expiring_soon_is_warned_about(): void
    {
        $company = Company::factory()->create([
            'efaktura_token_serial_number' => '1A2B3C',
            'efaktura_token_not_after' => now()->subDay(),
        ]);
        $accountant = $this->accountantFor($company);

        Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company])
            ->assertSee('Регистрираниот сертификат е истечен');

        $company->update(['efaktura_token_not_after' => now()->addDays(10)]);

        Livewire::actingAs($accountant)->test(CompanyProfile::class, ['company' => $company->fresh()])
            ->assertSee('Сертификатот истекува на')
            ->assertDontSee('е истечен');
    }
}
