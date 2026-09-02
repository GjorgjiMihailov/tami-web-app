<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
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
        Role::findOrCreate('client');
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

    public function test_client_cannot_register_a_signing_device(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
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
        $company = Company::factory()->create(['type' => \App\Support\CompanyType::INDIVIDUAL]);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }
}
