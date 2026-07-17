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
}
