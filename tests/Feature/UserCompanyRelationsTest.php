<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCompanyRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_user_belongs_to_one_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);

        $this->assertTrue($client->company->is($company));
    }

    public function test_an_accountant_can_be_assigned_to_multiple_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $accountant = User::factory()->create();

        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $this->assertCount(2, $accountant->assignedCompanies()->get());
        $this->assertTrue($accountant->assignedCompanies->contains($companyA));
        $this->assertTrue($accountant->assignedCompanies->contains($companyB));
    }
}
