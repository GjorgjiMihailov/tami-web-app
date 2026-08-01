<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEfakturaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_firm_mode_with_no_access(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->efaktura_firm_access_status);
        $this->assertFalse($company->hasEfakturaAccess());
    }

    public function test_firm_mode_has_access_only_once_approved(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED,
        ]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_own_mode_has_access_only_with_eujp_id_and_registered_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update([
            'efaktura_eujp_id' => 'EUJP-123',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_own_mode_without_registered_device_has_no_access(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-123',
        ]);

        $this->assertFalse($company->fresh()->hasEfakturaAccess());
    }
}
