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
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('client')) {
            return $user->company_id === $company->id;
        }

        if ($user->hasRole('accountant')) {
            return $user->assignedCompanies()->where('companies.id', $company->id)->exists();
        }

        return false;
    }
}
