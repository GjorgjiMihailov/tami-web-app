<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardEfakturaCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_admin_can_switch_company_to_own_efaktura_mode_with_certificate(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $cert = UploadedFile::fake()->create('cert.p12', 10);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->set('editEfakturaEujpId', 'EUJP-999')
            ->set('newEfakturaCertificate', $cert)
            ->set('editEfakturaCertificatePassword', 'pw-123')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $company->efaktura_credential_mode);
        $this->assertSame('EUJP-999', $company->efaktura_eujp_id);
        $this->assertSame('pw-123', $company->efaktura_certificate_password);
        Storage::disk('local')->assertExists($company->efaktura_certificate_path);
    }

    public function test_switching_back_to_firm_mode_clears_own_mode_secrets(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-999',
            'efaktura_certificate_path' => 'efaktura-certs/1/cert.p12',
            'efaktura_certificate_password' => 'pw-123',
        ]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_FIRM)
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertNull($company->efaktura_eujp_id);
        $this->assertNull($company->efaktura_certificate_path);
        $this->assertNull($company->efaktura_certificate_password);
    }

    public function test_client_cannot_edit_efaktura_credentials(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }
}
