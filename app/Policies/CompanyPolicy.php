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

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
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
