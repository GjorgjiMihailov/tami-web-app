<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_can_be_created_with_expected_fields(): void
    {
        $company = Company::factory()->create([
            'name' => 'Test Firm DOO',
            'tax_id' => '4030012345678',
        ]);

        $this->assertDatabaseHas('companies', [
            'name' => 'Test Firm DOO',
            'tax_id' => '4030012345678',
        ]);
        $this->assertEquals('Test Firm DOO', $company->fresh()->name);
    }

    public function test_a_company_can_be_created_with_profile_fields(): void
    {
        $company = Company::factory()->create([
            'short_name' => 'ТФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'logo_position' => 'center',
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'short_name' => 'ТФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'logo_position' => 'center',
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);
    }

    public function test_logo_position_defaults_to_left(): void
    {
        $company = Company::factory()->create();

        $this->assertEquals('left', $company->fresh()->logo_position);
    }

    public function test_a_company_can_have_up_to_five_bank_accounts_ordered_by_position(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07200002785123453', 'position' => 1]);
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);

        $this->assertEquals(
            ['Комерцијална банка', 'НЛБ Банка'],
            $company->bankAccounts()->pluck('bank_name')->all()
        );
    }

    public function test_deleting_a_company_deletes_its_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07200002785123453', 'position' => 0]);

        $company->delete();

        $this->assertDatabaseCount('company_bank_accounts', 0);
    }
}
