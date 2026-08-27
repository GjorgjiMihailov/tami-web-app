<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileEfakturaRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_client_can_request_firm_efaktura_access(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertSame(Company::EFAKTURA_STATUS_REQUESTED, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_request_is_a_no_op_when_company_is_in_own_mode(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_unrelated_user_cannot_request_access_for_a_company_they_cannot_view(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $otherCompany = Company::factory()->create();
        $unrelatedClient = User::factory()->create(['company_id' => $otherCompany->id]);
        $unrelatedClient->assignRole('client');

        Livewire::actingAs($unrelatedClient)
            ->test(CompanyProfile::class, ['company' => $company])
            ->assertForbidden();
    }
}
