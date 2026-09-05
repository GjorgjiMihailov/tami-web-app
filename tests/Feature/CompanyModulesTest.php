<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\CompanyModule;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_company_uses_every_module(): void
    {
        $company = Company::factory()->create();

        foreach (CompanyModule::cases() as $module) {
            $this->assertTrue(
                $company->usesModule($module),
                "Стандардно {$module->value} треба да е вклучен."
            );
        }
    }

    public function test_a_switched_off_module_reads_as_off(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);

        $this->assertFalse($company->usesModule(CompanyModule::PAYROLL));
        $this->assertTrue($company->usesModule(CompanyModule::FINANCE));
    }

    public function test_stock_is_off_when_material_is_off_whatever_the_column_says(): void
    {
        // Редот е намерно противречен: Залиха вклучена, Материјално исклучено.
        // Таква состојба формата не прави, но рака во базата може.
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->assertFalse($company->usesModule(CompanyModule::STOCK));
        $this->assertFalse($company->usesModule(CompanyModule::MATERIAL));
    }

    public function test_an_individual_profile_is_never_closed_by_a_module(): void
    {
        // Модулите не важат за физичко лице — типот веќе одлучува што гледа.
        // Дури и ако колоните се исклучени, ниту еден екран не смее да падне.
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'uses_material' => false,
            'uses_stock' => false,
            'uses_payroll' => false,
            'uses_finance' => false,
        ]);

        foreach (CompanyModule::cases() as $module) {
            $this->assertTrue($company->usesModule($module));
        }
    }
}
