<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileSigningDeviceTest extends TestCase
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

    public function test_admin_can_register_a_signing_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z');

        $company->refresh();
        $this->assertSame('1A2B3C', $company->efaktura_token_serial_number);
        $this->assertSame('CN=Test Company', $company->efaktura_token_subject_name);
        $this->assertNotNull($company->efaktura_token_registered_at);
    }

    public function test_accountant_can_register_a_signing_device(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company = Company::factory()->create();
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z');

        $this->assertSame('1A2B3C', $company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_of_an_own_mode_company_can_register_the_device(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
        ]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertHasNoErrors();

        $this->assertSame('1A2B3C', $company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_of_a_firm_mode_company_cannot_register_the_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_cannot_register_a_device_on_another_companys_profile(): void
    {
        $own = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $other = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-2']);
        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $other])
            ->assertForbidden();

        $this->assertNull($other->fresh()->efaktura_token_serial_number);
    }

    public function test_a_freelancer_client_cannot_register_the_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_cannot_switch_the_mode_or_the_eujp_id(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_FIRM)
            ->set('editEfakturaEujpId', 'EUJP-HACKED')
            ->call('save')
            ->assertForbidden();

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $company->efaktura_credential_mode);
        $this->assertSame('EUJP-1', $company->efaktura_eujp_id);
    }

    public function test_the_token_card_and_download_link_show_for_an_own_mode_client_but_not_a_firm_mode_client(): void
    {
        $own = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $ownClient = User::factory()->create(['company_id' => $own->id]);
        $ownClient->assignRole('internal_client');
        $firm = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $firmClient = User::factory()->create(['company_id' => $firm->id]);
        $firmClient->assignRole('internal_client');

        Livewire::actingAs($ownClient)
            ->test(CompanyProfile::class, ['company' => $own])
            ->assertSee('Преземи локален потпишувач')
            ->assertSee('Потпишувачки уред (USB токен)');

        Livewire::actingAs($firmClient)
            ->test(CompanyProfile::class, ['company' => $firm])
            ->assertDontSee('Преземи локален потпишувач')
            ->assertDontSee('Потпишувачки уред (USB токен)');
    }

    public function test_switching_to_own_mode_without_eujp_id_fails_validation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->call('save')
            ->assertHasErrors(['editEfakturaEujpId']);

        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->fresh()->efaktura_credential_mode);
    }

    /**
     * Потпишувачкиот уред служи за е-Фактура, што бара ЕДБ — физичко лице нема
     * што да потпишува. Картичката е скриена во профилот на физичко лице, но
     * Livewire метод се вика преку жица без разлика што е исцртано.
     */
    public function test_an_individual_profile_cannot_register_a_signing_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }
}
