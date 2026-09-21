<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_type_is_cast_to_the_enum(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
        ]);

        $this->assertSame(CompanyType::INDIVIDUAL, $company->fresh()->type);
    }

    public function test_a_profile_created_without_a_type_is_a_legal_entity(): void
    {
        $company = Company::create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->assertSame(CompanyType::LEGAL, $company->fresh()->type);
    }
}
