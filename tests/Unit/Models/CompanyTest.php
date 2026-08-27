<?php

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_type_is_cast_to_the_enum(): void
    {
        $company = \App\Models\Company::factory()->create([
            'type' => \App\Support\CompanyType::INDIVIDUAL,
        ]);

        $this->assertSame(\App\Support\CompanyType::INDIVIDUAL, $company->fresh()->type);
    }

    public function test_a_profile_created_without_a_type_is_a_legal_entity(): void
    {
        $company = \App\Models\Company::create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->assertSame(\App\Support\CompanyType::LEGAL, $company->fresh()->type);
    }
}
