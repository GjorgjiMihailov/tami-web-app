<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Services\CompanyCreator;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_entity_gets_a_tax_id_and_is_a_vat_payer(): void
    {
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, '4080012345678', '');

        $this->assertSame('ТЕСТ ДООЕЛ', $company->name);
        $this->assertTrue($company->type->isLegal());
        $this->assertSame('4080012345678', $company->tax_id);
        $this->assertNull($company->embg);
        $this->assertTrue($company->is_vat_registered);
    }

    public function test_an_individual_gets_an_embg_and_is_not_a_vat_payer(): void
    {
        $company = CompanyCreator::create('Петар Петров', CompanyType::INDIVIDUAL, '', '0101990450006');

        $this->assertTrue($company->type->isIndividual());
        $this->assertNull($company->tax_id);
        $this->assertSame('0101990450006', $company->embg);
        $this->assertFalse(
            $company->is_vat_registered,
            'Физичко лице не смее да остане ДДВ обврзник — инаку на фактурата излегува ДДВ што не постои.'
        );
    }

    public function test_the_type_dependent_defaults_are_set_on_the_instance_not_left_to_the_database(): void
    {
        // Стандардна вредност на колона во базата НЕ полни свежо создаден модел
        // во меморија. Затоа се проверува вратениот примерок, не повторно
        // прочитаниот ред.
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL);

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_finance);
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
    }
}
