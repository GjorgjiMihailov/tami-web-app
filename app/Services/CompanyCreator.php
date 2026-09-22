<?php

namespace App\Services;

use App\Models\Company;
use App\Support\CompanyType;

/**
 * Единственото место што создава фирма.
 *
 * Пишано како одделен клас зашто ДВА екрана создаваат фирми — „Фирми" на
 * админот (App\Livewire\CompanyIndex) и екранот за прв клиент
 * (App\Livewire\FirstClient). Две копии од листата подолу се разидуваат, а
 * разликата се гледа дури на печатена фактура.
 *
 * Ниту едно поле што зависи од типот не смее да остане на стандардна вредност
 * од базата: стандардна вредност на колона НЕ полни свежо создаден модел во
 * меморија, па физичко лице би останало ДДВ обврзник сè до првото повторно
 * читање од базата. Причината е опишана во
 * docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
 */
class CompanyCreator
{
    public static function create(
        string $name,
        CompanyType $type,
        ?string $taxId = null,
        ?string $embg = null,
    ): Company {
        $isLegal = $type->isLegal();

        return Company::create([
            'name' => $name,
            'type' => $type,
            'tax_id' => $isLegal ? ($taxId ?: null) : null,
            'embg' => $isLegal ? null : ($embg ?: null),
            'is_vat_registered' => $isLegal,
            // Сите модули вклучени; се исклучуваат на картичката „Модули".
            'uses_material' => true,
            'uses_stock' => true,
            'uses_payroll' => true,
            'uses_finance' => true,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
        ]);
    }
}
