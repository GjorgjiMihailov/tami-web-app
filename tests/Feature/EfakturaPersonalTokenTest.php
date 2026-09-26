<?php

namespace Tests\Feature;

use App\Livewire\Profile\EfakturaToken;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Efaktura\EfakturaJwsService;
use App\Support\CompanyType;
use App\Support\EfakturaSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaPersonalTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function giveToken(User $user, string $eujp = 'EUJP-ACC', string $serial = 'AAA111'): User
    {
        $user->forceFill(['efaktura_eujp_id' => $eujp, 'efaktura_token_serial_number' => $serial])->save();

        return $user->fresh();
    }

    // ---- Правото ----

    public function test_an_accountant_with_a_personal_token_can_sign_for_every_client_they_work_on_and_only_those(): void
    {
        $mine = Company::factory()->create();
        $alsoMine = Company::factory()->create();
        $notMine = Company::factory()->create();
        $accountant = $this->giveToken($this->user('accountant'));
        $mine->accountants()->attach($accountant);
        $alsoMine->accountants()->attach($accountant);

        $this->assertTrue($accountant->can('signEfaktura', $mine));
        $this->assertTrue($accountant->can('signEfaktura', $alsoMine));
        $this->assertFalse($accountant->can('signEfaktura', $notMine));
    }

    public function test_without_a_personal_token_nobody_can_sign_not_even_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = $this->user('admin');
        $accountant = $this->user('accountant');
        $company->accountants()->attach($accountant);

        $this->assertFalse($admin->can('signEfaktura', $company));
        $this->assertFalse($accountant->can('signEfaktura', $company));
        // ... но смеат да подготвуваат (гледаат совет и се упатуваат кон профилот).
        $this->assertTrue($admin->can('workWithEfaktura', $company));
        $this->assertTrue($accountant->can('workWithEfaktura', $company));
    }

    public function test_the_token_needs_the_eujp_id_too(): void
    {
        $company = Company::factory()->create();
        $admin = $this->user('admin', ['efaktura_token_serial_number' => 'AAA111']);

        $this->assertFalse($admin->can('signEfaktura', $company));

        $admin->forceFill(['efaktura_eujp_id' => 'EUJP-1'])->save();
        $this->assertTrue($admin->fresh()->can('signEfaktura', $company));
    }

    public function test_a_client_with_a_personal_token_signs_only_for_their_own_company(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $client = $this->giveToken($this->user('internal_client', ['company_id' => $own->id]), 'EUJP-CL', 'CCC333');

        $this->assertTrue($client->can('signEfaktura', $own));
        $this->assertFalse($client->can('signEfaktura', $other));
    }

    public function test_a_freelancer_never_gets_e_faktura(): void
    {
        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $freelancer = $this->giveToken($this->user('freelancer_client', ['company_id' => $individual->id]));
        $admin = $this->giveToken($this->user('admin'));

        $this->assertFalse($freelancer->can('signEfaktura', $individual));
        $this->assertFalse($freelancer->can('workWithEfaktura', $individual));
    }

    public function test_the_signer_is_the_users_token_and_the_old_company_token_is_only_a_fallback(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-FIRMA',
            'efaktura_token_serial_number' => 'FIRMA-SN',
        ]);
        $accountant = $this->user('accountant');

        // Без свој токен: важи постариот запис на фирмата.
        $fallback = $accountant->efakturaSignerFor($company);
        $this->assertSame('EUJP-FIRMA', $fallback->eujpId);
        $this->assertSame(EfakturaSigner::SOURCE_COMPANY, $fallback->source);

        // Со свој токен: тој има предност.
        $own = $this->giveToken($accountant)->efakturaSignerFor($company);
        $this->assertSame('EUJP-ACC', $own->eujpId);
        $this->assertSame('AAA111', $own->serialNumber);
        $this->assertSame(EfakturaSigner::SOURCE_USER, $own->source);

        // Фирма во режим „канцеларија“ без свој токен на корисникот: нема идентитет.
        $firm = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $this->assertNull($this->user('accountant')->efakturaSignerFor($firm));
    }

    // ---- Заглавија кон УЈП ----

    public function test_the_request_carries_the_signers_eujp_id_and_serial_and_the_companys_edb(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_eujp_id' => 'EUJP-FIRMA', 'efaktura_token_serial_number' => 'FIRMA-SN',
        ]);
        $signer = new EfakturaSigner('EUJP-SMETKOVODITEL', 'TOKEN-SN', EfakturaSigner::SOURCE_USER);

        (new EfakturaJwsService)->send($company, $signer, 'header.payload', 'c2ln');

        Http::assertSent(fn ($request) => $request->header('X-EUJP-ID')[0] === 'EUJP-SMETKOVODITEL'
            && $request->header('X-SERIAL-NUMBER')[0] === 'TOKEN-SN'
            && $request->header('X-EDB')[0] === '4030001234567');
    }

    public function test_the_signing_endpoint_works_for_a_user_with_a_token_and_is_forbidden_without_one(): void
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567', 'street_address' => 'Х', 'street_number' => '1', 'postal_code' => '1000', 'city' => 'Скопје']);
        $partner = Partner::factory()->for($company)->create(['street_address' => 'Ул', 'street_number' => '1', 'postal_code' => '1000', 'city' => 'Скопје']);
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00', 'vat_treatment' => 'standard']);
        $accountant = $this->user('accountant');
        $company->accountants()->attach($accountant);
        $url = route('sales-invoices.efaktura.signing-input', [$company, $invoice]);

        $this->actingAs($accountant)->postJson($url, ['certificateBase64' => base64_encode('cert')])->assertForbidden();

        $this->actingAs($this->giveToken($accountant))->postJson($url, ['certificateBase64' => base64_encode('cert')])->assertOk();
    }

    // ---- Профил: регистрирање и ажурирање ----

    public function test_a_user_registers_and_updates_their_own_token_and_eujp_id(): void
    {
        $accountant = $this->user('accountant');

        $component = Livewire::actingAs($accountant)->test(EfakturaToken::class);
        $component->assertSee('Регистрирај токен')->assertDontSee('Ажурирај сертификат');

        $component->set('eujpId', 'EUJP-NOV')->call('saveEujpId')->assertHasNoErrors();
        $component->call('registerSigningDevice', 'AAA111', 'CN=Сметководител', '2025-01-01T00:00:00Z', now()->addYear()->toIso8601String());

        $fresh = $accountant->fresh();
        $this->assertSame('EUJP-NOV', $fresh->efaktura_eujp_id);
        $this->assertSame('AAA111', $fresh->efaktura_token_serial_number);
        $this->assertNotNull($fresh->efaktura_token_registered_at);

        Livewire::actingAs($fresh)->test(EfakturaToken::class)
            ->assertSee('Ажурирај сертификат')
            ->assertSee('замени го регистрираниот токен')
            ->call('registerSigningDevice', 'BBB222', 'CN=Нов', '2025-01-01T00:00:00Z', now()->addYear()->toIso8601String());

        $this->assertSame('BBB222', $accountant->fresh()->efaktura_token_serial_number);
    }

    public function test_the_token_can_be_removed_and_bad_serials_are_rejected(): void
    {
        $admin = $this->giveToken($this->user('admin'));

        $component = Livewire::actingAs($admin)->test(EfakturaToken::class);
        $component->call('registerSigningDevice', str_repeat('A', 101), 'CN=X', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertHasErrors(['signingDevice']);
        $component->call('registerSigningDevice', "AB\nCD", 'CN=X', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertHasErrors(['signingDevice']);
        $this->assertSame('AAA111', $admin->fresh()->efaktura_token_serial_number);   // непроменет

        $component->call('removeToken');
        $this->assertNull($admin->fresh()->efaktura_token_serial_number);
        $this->assertFalse($admin->fresh()->can('signEfaktura', Company::factory()->create()));
    }

    public function test_an_expired_certificate_is_flagged_and_one_expiring_soon_is_warned_about(): void
    {
        $user = $this->giveToken($this->user('accountant'));
        $user->forceFill(['efaktura_token_not_after' => now()->subDay()])->save();

        Livewire::actingAs($user->fresh())->test(EfakturaToken::class)->assertSee('Сертификатот е истечен');

        $user->forceFill(['efaktura_token_not_after' => now()->addDays(10)])->save();

        Livewire::actingAs($user->fresh())->test(EfakturaToken::class)
            ->assertSee('Сертификатот истекува на')
            ->assertDontSee('е истечен');
    }

    public function test_a_freelancer_cannot_open_or_use_the_token_component(): void
    {
        $freelancer = $this->user('freelancer_client');

        $this->actingAs($freelancer);
        Livewire::test(EfakturaToken::class)->assertForbidden();
        $this->assertNull($freelancer->fresh()->efaktura_eujp_id);
    }

    public function test_the_profile_page_shows_the_token_card_to_the_right_roles_only(): void
    {
        $this->actingAs($this->user('accountant'))->get(route('profile'))->assertOk()->assertSee('е-Фактура — мој токен');
        $this->actingAs($this->user('admin'))->get(route('profile'))->assertOk()->assertSee('е-Фактура — мој токен');
        $this->actingAs($this->user('freelancer_client'))->get(route('profile'))->assertOk()->assertDontSee('е-Фактура — мој токен');
    }
}
