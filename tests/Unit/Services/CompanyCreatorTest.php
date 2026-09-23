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

    public function test_an_accountant_actor_is_attached_as_the_companys_accountant(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('accountant');
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');

        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, actor: $accountant);

        $this->assertTrue(
            $company->accountants->contains($accountant),
            'Без ова сметководителот веднаш ја губи фирмата што штотуку ја создал.'
        );
    }

    public function test_an_admin_actor_is_not_attached(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin');
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, actor: $admin);

        $this->assertFalse(
            $company->accountants->contains($admin),
            'Админ гледа сè без ред во company_accountant — закачување би било вишок ред.'
        );
    }

    public function test_no_actor_means_no_attachment(): void
    {
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL);

        $this->assertCount(0, $company->accountants);
    }
}
