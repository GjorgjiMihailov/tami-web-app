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
     * Админ секогаш. Сметководител — само додека нема ниту една фирма, за да
     * може сам да го внесе првиот клиент (App\Livewire\FirstClient).
     *
     * Правилото се затвора само по себе: штом првата фирма е создадена и
     * закачена на него, visibleCompanies() повеќе не е празно. Намерно не е
     * напишано како трајно право — тоа би била друга одлука од таа во
     * docs/superpowers/specs/2026-09-22-first-client-app-board-and-motion-design.md.
     *
     * Ова е право на ДЕЈСТВОТО, не на екранот: „Фирми" (App\Livewire\CompanyIndex)
     * останува само за админ.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant') && ! $user->visibleCompanies()->exists();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('admin');
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
