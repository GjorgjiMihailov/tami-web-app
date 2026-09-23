<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->visibleCompanies()->whereKey($company->id)->exists();
    }

    /**
     * Админ секогаш. Сметководител — секогаш, без ограничување: сам ги
     * внесува сите свои клиенти, не само првиот. Порано ова важеше само
     * додека немаше ниту една фирма (излезот за App\Livewire\FirstClient);
     * тоа ограничување е тргнато со изречна одлука на сопственикот — види
     * docs/superpowers/specs/2026-09-23-accountant-self-service-companies-design.md.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('accountant');
    }

    /**
     * Вратата за целата поставка на фирмата: профил (CompanyProfile),
     * модули (CompanyModules), и корисници (CompanyUsers, преку ова исто
     * правило — не UserPolicy::create, кое мора да остане админ-само зашто
     * го користи и OfficeUsers за сметки на канцеларијата).
     *
     * Админ секогаш. Сметководител — само за фирма на која работи
     * (visibleCompanies() ја содржи).
     */
    public function update(User $user, Company $company): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant')
            && $user->visibleCompanies()->whereKey($company->id)->exists();
    }

    /**
     * Пошироко од `update` намерно: форматот на бројот на фактурата е одлука на
     * клиентот, а целиот профил на фирмата останува само за админ.
     */
    public function updateInvoiceSettings(User $user, Company $company): bool
    {
        return $this->view($user, $company);
    }
}
