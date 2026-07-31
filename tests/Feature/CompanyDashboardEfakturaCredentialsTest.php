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

    /**
     * Locates a usable openssl.cnf on this machine. PHP's openssl_* key/CSR functions
     * need a config file to find their default section; on some Windows setups the
     * compiled-in default path doesn't exist, so we search common locations (including
     * the one bundled with Git for Windows) and pass it explicitly when found.
     */
    private function opensslConfigPath(): ?string
    {
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            'C:\\Program Files\\Common Files\\SSL\\openssl.cnf',
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ] as $candidate) {
            if ($candidate && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Generates a real PKCS#12 (.p12) fixture at test time by creating a throwaway
     * self-signed cert+key and exporting it with a known password, so tests exercise
     * the actual openssl_pkcs12_read validation path instead of a filename-sniffed fake.
     */
    private function makeRealCertificateFixture(string $password): UploadedFile
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if ($configPath = $this->opensslConfigPath()) {
            $config['config'] = $configPath;
        }

        $privateKey = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => 'Test Company'], $privateKey, $config);
        $cert = openssl_csr_sign($csr, null, $privateKey, 1, $config);

        openssl_pkcs12_export($cert, $pkcs12, $privateKey, $password);

        // Livewire's Testable::upload() reads a public ->name property that only
        // Illuminate\Http\Testing\File (the UploadedFile::fake() subclass) exposes —
        // a plain `new UploadedFile(...)` fails when passed through ->set(). Using
        // createWithContent() keeps the real PKCS#12 bytes while satisfying Livewire's
        // testing transport, so openssl_pkcs12_read still runs against genuine content.
        return UploadedFile::fake()->createWithContent('cert.p12', $pkcs12);
    }

    public function test_admin_can_switch_company_to_own_efaktura_mode_with_certificate(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $cert = $this->makeRealCertificateFixture('pw-123');

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

    public function test_certificate_uploaded_with_wrong_password_is_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $cert = $this->makeRealCertificateFixture('correct-password');

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->set('editEfakturaEujpId', 'EUJP-999')
            ->set('newEfakturaCertificate', $cert)
            ->set('editEfakturaCertificatePassword', 'wrong-password')
            ->call('save')
            ->assertHasErrors(['newEfakturaCertificate']);

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertNull($company->efaktura_eujp_id);
        $this->assertNull($company->efaktura_certificate_path);
        $this->assertNull($company->efaktura_certificate_password);
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

    public function test_editing_unrelated_field_preserves_existing_efaktura_secrets(): void
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
            ->set('editName', 'Renamed Company Ltd')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('Renamed Company Ltd', $company->name);
        $this->assertSame('EUJP-999', $company->efaktura_eujp_id);
        $this->assertSame('efaktura-certs/1/cert.p12', $company->efaktura_certificate_path);
        $this->assertSame('pw-123', $company->efaktura_certificate_password);
    }

    public function test_switching_to_own_mode_with_nothing_filled_in_fails_validation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->call('save')
            ->assertHasErrors([
                'editEfakturaEujpId',
                'newEfakturaCertificate',
                'editEfakturaCertificatePassword',
            ]);

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertNull($company->efaktura_eujp_id);
        $this->assertNull($company->efaktura_certificate_path);
        $this->assertNull($company->efaktura_certificate_password);
    }

    public function test_existing_own_mode_company_with_stored_certificate_can_save_without_reuploading(): void
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
            ->set('editName', 'Renamed Again Ltd')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('Renamed Again Ltd', $company->name);
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $company->efaktura_credential_mode);
        $this->assertSame('EUJP-999', $company->efaktura_eujp_id);
        $this->assertSame('efaktura-certs/1/cert.p12', $company->efaktura_certificate_path);
        $this->assertSame('pw-123', $company->efaktura_certificate_password);
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
