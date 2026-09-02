<?php

namespace Tests\Unit;

use App\Support\CompanyType;
use PHPUnit\Framework\TestCase;

class CompanyTypeTest extends TestCase
{
    public function test_a_legal_entity_is_not_an_individual(): void
    {
        $this->assertTrue(CompanyType::LEGAL->isLegal());
        $this->assertFalse(CompanyType::LEGAL->isIndividual());
        $this->assertSame('legal', CompanyType::LEGAL->value);
    }

    public function test_an_individual_is_not_a_legal_entity(): void
    {
        $this->assertTrue(CompanyType::INDIVIDUAL->isIndividual());
        $this->assertFalse(CompanyType::INDIVIDUAL->isLegal());
        $this->assertSame('individual', CompanyType::INDIVIDUAL->value);
    }

    public function test_every_case_carries_its_macedonian_label(): void
    {
        $this->assertSame('Правно лице', CompanyType::LEGAL->label());
        $this->assertSame('Физичко лице', CompanyType::INDIVIDUAL->label());
    }
}
