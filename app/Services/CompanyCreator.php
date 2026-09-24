<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Support\Str;

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
        ?User $actor = null,
    ): Company {
        $isLegal = $type->isLegal();

        $company = Company::create([
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

        // Сметководител што создава фирма мора веднаш да ја гледа — инаку
        // исчезнува од сопствениот список штом visibleCompanies() се
        // пресмета одново. Админ никогаш не се закачува: тој гледа сè и без
        // ред во пивот-табелата (Company::accountants()).
        //
        // Овој чекор намерно живее ТУКА, не во секој повикувачки екран
        // одделно — двете места (CompanyIndex, FirstClient) веќе еднаш се
        // разидоа кога закачувањето беше рачно во секој од нив.
        if ($actor?->hasRole('accountant')) {
            $company->accountants()->attach($actor->id);
        }

        return $company;
    }

    /**
     * Сметката за најава на фирмата — клиентот преку неа внесува фактури,
     * потпишува, качува изводи. Лозинка нема: вистинска се поставува преку
     * поканата (со оваа случајна не може да се влезе).
     */
    public static function createLogin(Company $company, string $name, string $email): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Str::random(64),
        ]);
        // company_id не е во #[Fillable] на моделот.
        $user->forceFill(['company_id' => $company->id])->save();
        $user->assignRole($company->type->clientRole());

        return $user;
    }
}
